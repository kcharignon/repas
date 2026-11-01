<?php

namespace Repas\Tests\User\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Psr\Cache\CacheItemPoolInterface;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Infrastructure\AppVersion\LastSeenAppVersions;
use Repas\User\Domain\Model\User;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La version de l'application retenue sur toute requête authentifiée qui porte `X-App-Version` et `X-App-Platform` (DRV-758), de
 * bout en bout : vrai jeton, vrais bus, vraie base, vrai limiteur et vraie mémoire (noyau gardé d'une requête à l'autre).
 */
class AppVersionOnEveryRequestFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    public function testAnyAuthenticatedRequestRecordsTheVersionAndTheAdministratorSeesIt(): void
    {
        // Given : un compte qui n'a jamais appelé le PUT
        $user = $this->savedUser(new UserBuilder()->withEmail('a-telephone@example.com'));
        $admin = $this->savedUser(new UserBuilder()->withEmail('b-admin@example.com')->isAdmin());

        // When : une simple lecture qui porte la version
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[$user->getId(), 'android', '0.1.8']], $this->rows());
        $this->get('/api/v1/users', $admin);
        $this->assertSame(
            [['a-telephone@example.com', '0.1.8'], ['b-admin@example.com', null]],
            array_map(static fn(array $member): array => [$member['email'], $member['appVersion']], $this->jsonBody()['member']),
        );
    }

    public function testTheResponseIsTheSameWithOrWithoutTheHeaders(): void
    {
        // Given
        $user = $this->savedUser(new UserBuilder());
        $this->get('/api/v1/me', $user);
        $without = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        // When
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then
        $this->assertSame($without, [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()]);
    }

    public function testAKnownVersionCostsNoQueryAtAll(): void
    {
        // Given : la version est déjà connue (premier appel : écriture après la réponse)
        $user = $this->savedUser(new UserBuilder());
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->get('/api/v1/me', $user, '0.1.8');
        $counter->reset();
        $this->get('/api/v1/me', $user);
        $withoutHeaders = $counter->statements();
        $counter->reset();

        // When : la même requête, avec la même version
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then : exactement les mêmes requêtes SQL que sans en-tête, aucune écriture
        $this->assertNotSame([], $withoutHeaders);
        $this->assertSame($withoutHeaders, $counter->statements());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_starts_with($sql, 'INSERT'))));
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'INSERT INTO user_app_version'))));
    }

    public function testTheFirstSightingCostsOneStatementAfterTheResponseAndTheNewVersionWinsAfterTheMinute(): void
    {
        // Given
        $user = $this->savedUser(new UserBuilder());
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->get('/api/v1/me', $user, '0.1.8');

        // When : la mise à jour dans la même minute, puis la minute écoulée
        $this->get('/api/v1/me', $user, '0.1.9');
        $this->assertSame('0.1.8', $this->rows()[0][2]);
        new RateLimiterTimeTravel(static::getContainer()->get('test.rate_limiter_pool'))->advance('app_version', 'user-' . $user->getId(), 120);
        $counter->reset();
        $this->get('/api/v1/me', $user, '0.1.9');

        // Then : une seule écriture de plus, la version la plus récente
        $this->assertSame([[$user->getId(), 'android', '0.1.9']], $this->rows());
        $this->assertCount(1, array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'INSERT INTO user_app_version')));
    }

    public function testAnAppThatPutItsVersionIsNotWrittenAgainByTheNextRequests(): void
    {
        // Given
        $user = $this->savedUser(new UserBuilder());
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->client->request(
            'PUT',
            '/api/v1/users/me/app-version',
            content: json_encode(['platform' => 'android', 'version' => '0.1.8'], JSON_THROW_ON_ERROR),
            server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json', 'HTTP_X_APP_VERSION' => '0.1.8', 'HTTP_X_APP_PLATFORM' => 'android'],
        );
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $counter->reset();

        // When
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'INSERT INTO user_app_version'))));
    }

    public function testAMalformedVersionOrAnUnknownPlatformIsIgnoredWithoutError(): void
    {
        // Given
        $user = $this->savedUser(new UserBuilder());

        // When
        $this->get('/api/v1/me', $user, '0.0.0-dev');
        $malformed = $this->client->getResponse()->getStatusCode();
        $this->get('/api/v1/me', $user, '0.1.8', 'ios');

        // Then
        $this->assertSame(200, $malformed);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->rows());
    }

    public function testAPublicRouteRecordsNothing(): void
    {
        // Given : aucun jeton, une route publique de /api/v1
        // When
        $this->client->request(
            'POST',
            '/api/v1/users/password-reset/send',
            content: json_encode(['email' => 'nobody@example.com'], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_APP_VERSION' => '0.1.8', 'HTTP_X_APP_PLATFORM' => 'android'],
        );

        // Then
        $this->assertSame([], $this->rows());
    }

    public function testADisabledAccountRecordsNothing(): void
    {
        // Given
        $user = $this->savedUser(new UserBuilder()->isDisabled());

        // When
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then : refusé comme avant, rien d'écrit
        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
        $this->assertSame([], $this->rows());
    }

    public function testAFailingWriteDoesNotChangeTheResponse(): void
    {
        // Given : le bus de commandes échoue sur l'écriture de la version
        $commandBus = new MockCommandBus();
        $commandBus->willThrow(UpdateUserAppVersionCommand::class, new RuntimeException('panne'));
        static::getContainer()->set(CommandBusInterface::class, $commandBus);
        $user = $this->savedUser(new UserBuilder());
        $this->get('/api/v1/me', $user);
        $expected = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        // When
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then
        $this->assertSame($expected, [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()]);
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testALimitedPutDoesNotUpdateTheMemory(): void
    {
        // Given : un premier PUT écrit 0.1.8
        $user = $this->savedUser(new UserBuilder());
        $put = fn(string $version) => $this->client->request(
            'PUT',
            '/api/v1/users/me/app-version',
            content: json_encode(['platform' => 'android', 'version' => $version], JSON_THROW_ON_ERROR),
            server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'],
        );
        $put('0.1.8');

        // When : un second PUT dans la minute, refusé par le limiteur (204 sans écriture)
        $put('0.1.9');

        // Then : ni la ligne ni la mémoire ne bougent
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('0.1.8', $this->rows()[0][2]);
        $this->assertSame('0.1.8', static::getContainer()->get(LastSeenAppVersions::class)->of($user->getId(), AppPlatform::ANDROID)?->toString());
    }

    public function testAnOlderVersionDoesNotConsumeTheLimiter(): void
    {
        // Given : 0.1.9 connue de la mémoire, le limiteur du compte intact
        $user = $this->savedUser(new UserBuilder());
        static::getContainer()->get(LastSeenAppVersions::class)->remember($user->getId(), AppPlatform::ANDROID, AppVersion::fromString('0.1.9'));

        // When : un appareil plus ancien passe, puis la mise à jour
        $this->get('/api/v1/me', $user, '0.1.8');
        $this->assertSame([], $this->rows());
        $this->get('/api/v1/me', $user, '0.1.10');

        // Then : la mise à jour est écrite tout de suite
        $this->assertSame([[$user->getId(), 'android', '0.1.10']], $this->rows());
    }

    public function testAFailingCacheDoesNotChangeTheResponseNorWrite(): void
    {
        // Given : le cache de la mémoire lève
        static::getContainer()->set('repas.app_version_cache', $this->brokenPool());
        $user = $this->savedUser(new UserBuilder());
        $this->get('/api/v1/me', $user);
        $expected = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        // When
        $this->get('/api/v1/me', $user, '0.1.8');

        // Then
        $this->assertSame($expected, [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame([], $this->rows());
    }

    public function testThePreflightAllowsTheVersionHeaders(): void
    {
        // Given : le site de développement (autre origine) annonce les en-têtes qu'il enverra
        // When
        $this->client->request('OPTIONS', '/api/v1/me', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-app-version,x-app-platform',
        ]);

        // Then
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [200, 204]);
        $allowed = array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Allow-Headers'))));
        $this->assertContains('x-app-version', $allowed);
        $this->assertContains('x-app-platform', $allowed);
    }

    private function brokenPool(): CacheItemPoolInterface
    {
        return new class implements CacheItemPoolInterface {
            public function getItem(string $key): \Psr\Cache\CacheItemInterface
            {
                throw new RuntimeException('cache en panne');
            }

            public function getItems(array $keys = []): iterable
            {
                throw new RuntimeException('cache en panne');
            }

            public function hasItem(string $key): bool
            {
                throw new RuntimeException('cache en panne');
            }

            public function clear(): bool
            {
                return true;
            }

            public function deleteItem(string $key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        };
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function get(string $uri, User $user, ?string $version = null, string $platform = 'android'): void
    {
        $server = $this->bearerFor($user);
        if ($version !== null) {
            $server += ['HTTP_X_APP_VERSION' => $version, 'HTTP_X_APP_PLATFORM' => $platform];
        }
        $this->client->request('GET', $uri, server: $server);
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function rows(): array
    {
        return array_map(
            static fn(array $row): array => [$row['user_id'], $row['platform'], $row['version']],
            static::getContainer()->get('doctrine')->getConnection()->fetchAllAssociative('SELECT user_id, platform, version FROM user_app_version ORDER BY user_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

namespace Repas\Tests\User\InputAdapter;


use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledAppReleaseProvider;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\AppReleaseProvider;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La version de l'application connue du serveur (DRV-637) de bout en bout : vrai jeton, vrais bus, vraie base, vrai limiteur. Les
 * versions publiées (`APP_LATEST_VERSION`, `APP_MIN_VERSION`, vides en test) sont fixées par le test ; le noyau est gardé d'une
 * requête à l'autre (`disableReboot`) pour que ce choix et le limiteur tiennent.
 */
class AppVersionApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    public function testAnOldAppIsInvitedToUpdateAndTheAdministratorSeesIt(): void
    {
        // Given : APP_LATEST_VERSION=0.1.10, une application 0.1.8 connectée
        $this->publishedVersions('0.1.10', null);
        $user = $this->savedUser(new UserBuilder()->withEmail('a-telephone@example.com'));
        $admin = $this->savedUser(new UserBuilder()->withEmail('b-admin@example.com')->isAdmin());

        // When : l'application démarre
        $this->putVersion($user, '0.1.8');

        // Then : la table contient android / 0.1.8 pour ce compte
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            [['user_id' => $user->getId(), 'platform' => 'android', 'version' => '0.1.8']],
            $this->connection()->fetchAllAssociative('SELECT user_id, platform, version FROM user_app_version'),
        );
        // Then : l'écran « Moi » de cette personne apprend qu'une nouvelle version est prête
        $this->get('/api/v1/me', $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['latest' => '0.1.10', 'minimum' => null, 'outdated' => true], $this->jsonBody()['appUpdate']);
        // Then : l'administrateur la voit « à mettre à jour »
        $this->get('/api/v1/users', $admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            [
                ['a-telephone@example.com', '0.1.8', true],
                ['b-admin@example.com', null, false],
            ],
            array_map(static fn(array $member): array => [$member['email'], $member['appVersion'], $member['appOutdated']], $this->jsonBody()['member']),
        );
    }

    public function testASecondStartInTheMinuteWritesNothing(): void
    {
        // Given : un premier démarrage
        $user = $this->savedUser(new UserBuilder());
        $this->putVersion($user, '0.1.8');
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : deuxième démarrage dans la minute
        $this->putVersion($user, '0.1.8');

        // Then : aucune erreur et aucune écriture de plus (aucune requête SQL hors sécurité)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'user_app_version'))));
        $this->assertSame('0.1.8', $this->connection()->fetchOne('SELECT version FROM user_app_version'));
    }

    public function testAnAppThatIsUpToDateIsNotInvited(): void
    {
        // Given : APP_LATEST_VERSION=0.1.9, APP_MIN_VERSION=0.1.9, une application 0.1.10 (« 0.1.10 » < « 0.1.9 » en texte)
        $this->publishedVersions('0.1.9', '0.1.9');
        $user = $this->savedUser(new UserBuilder());

        // When
        $this->putVersion($user, '0.1.10');
        $this->get('/api/v1/me', $user);

        // Then
        $this->assertSame(['latest' => '0.1.9', 'minimum' => '0.1.9', 'outdated' => false], $this->jsonBody()['appUpdate']);
    }

    public function testInABrowserNothingIsKnownAndNobodyIsInvited(): void
    {
        // Given : APP_LATEST_VERSION=0.1.10, un compte qui n'a jamais envoyé de version
        $this->publishedVersions('0.1.10', '0.1.9');
        $user = $this->savedUser(new UserBuilder());

        // When
        $this->get('/api/v1/me', $user);

        // Then : jamais d'erreur, aucune invitation
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['latest' => '0.1.10', 'minimum' => '0.1.9', 'outdated' => false], $this->jsonBody()['appUpdate']);
    }

    public function testWithoutServerSettingsNobodyIsInvited(): void
    {
        // Given : APP_LATEST_VERSION et APP_MIN_VERSION vides (le défaut, ici celui de .env.test)
        $user = $this->savedUser(new UserBuilder());
        $this->putVersion($user, '0.0.1');

        // When
        $this->get('/api/v1/me', $user);

        // Then
        $this->assertSame(['latest' => null, 'minimum' => null, 'outdated' => false], $this->jsonBody()['appUpdate']);
    }

    /**
     * `APP_LATEST_VERSION` et `APP_MIN_VERSION` vus par le serveur, avant la première requête (le noyau est gardé).
     */
    private function publishedVersions(?string $latest, ?string $minimum): void
    {
        static::getContainer()->set(AppReleaseProvider::class, new ControlledAppReleaseProvider($latest, $minimum));
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function putVersion(User $user, string $version): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/users/me/app-version',
            content: json_encode(['platform' => 'android', 'version' => $version], JSON_THROW_ON_ERROR),
            server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'],
        );
    }

    private function get(string $uri, User $user): void
    {
        $this->client->request('GET', $uri, server: $this->bearerFor($user));
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

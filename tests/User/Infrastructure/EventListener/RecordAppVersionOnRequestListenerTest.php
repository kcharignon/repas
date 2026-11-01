<?php

namespace Repas\Tests\User\Infrastructure\EventListener;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\SecurityUserDouble;
use Repas\Tests\Helper\SpyLogger;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Infrastructure\AppVersion\LastSeenAppVersions;
use Repas\User\Infrastructure\EventListener\RecordAppVersionOnRequestListener;
use Repas\User\Infrastructure\Http\Api\UpdateUserAppVersion\UpdateUserAppVersionApiController;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * La version de l'application retenue sur toute requête authentifiée qui porte les en-têtes (DRV-758) : écouteur d'infrastructure
 * avec le vrai limiteur et la vraie mémoire (en mémoire), un double du bus de commandes.
 */
class RecordAppVersionOnRequestListenerTest extends TestCase
{
    private const string USER_ID = '11111111-1111-4111-8111-111111111111';

    private TokenStorage $tokenStorage;
    private MockCommandBus $commandBus;
    private SpyLogger $logger;
    private RecordAppVersionOnRequestListener $listener;
    private LastSeenAppVersions $memory;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->commandBus = new MockCommandBus();
        $this->logger = new SpyLogger();
        $this->memory = new LastSeenAppVersions(new ArrayAdapter());
        $limiter = new RateLimiterFactory(['id' => 'app_version', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());
        $this->listener = new RecordAppVersionOnRequestListener(
            $this->tokenStorage,
            fn(): LastSeenAppVersions => $this->memory,
            fn(): MockCommandBus => $this->commandBus,
            static fn(): RateLimiterFactory => $limiter,
            $this->logger,
        );
    }

    public function testTheVersionOfAnAuthenticatedRequestIsRecorded(): void
    {
        // Given
        $this->authenticate();

        // When
        $this->terminate($this->request('0.1.8'));

        // Then
        $this->assertEquals([new UpdateUserAppVersionCommand(self::USER_ID, AppPlatform::ANDROID, '0.1.8')], $this->commandBus->dispatched());
    }

    public function testTheSameVersionAgainWritesNothing(): void
    {
        // Given : la version est déjà connue
        $this->authenticate();
        $this->terminate($this->request('0.1.8'));

        // When
        $this->terminate($this->request('0.1.8'));
        $this->terminate($this->request('0.1.8'));

        // Then
        $this->assertCount(1, $this->commandBus->dispatched());
    }

    public function testTheMostRecentVersionWins(): void
    {
        // Given : 0.1.9 retenue, la minute écoulée (un limiteur neuf)
        $this->authenticate();
        $this->memory->remember(self::USER_ID, AppPlatform::ANDROID, AppVersion::fromString('0.1.9'));

        // When : un autre appareil du compte, plus ancien, puis une mise à jour (0.1.10 est plus récente que 0.1.9)
        $this->terminate($this->request('0.1.8'));
        $this->terminate($this->request('0.1.10'));

        // Then : seule la plus récente est écrite
        $this->assertEquals([new UpdateUserAppVersionCommand(self::USER_ID, AppPlatform::ANDROID, '0.1.10')], $this->commandBus->dispatched());
    }

    public function testAtMostOneWritePerMinuteAndTheNewVersionIsTakenUpLater(): void
    {
        // Given : 0.1.8 écrite à l'instant
        $this->authenticate();
        $this->terminate($this->request('0.1.8'));

        // When : la mise à jour arrive dans la même minute
        $this->terminate($this->request('0.1.9'));

        // Then : rien d'écrit, et la mémoire garde 0.1.8 pour que la requête suivante (après la minute) l'écrive
        $this->assertCount(1, $this->commandBus->dispatched());
        $this->assertSame('0.1.8', $this->memory->of(self::USER_ID, AppPlatform::ANDROID)?->toString());
    }

    #[DataProvider('unusableVersions')]
    public function testAMalformedVersionIsIgnored(string $version): void
    {
        // Given
        $this->authenticate();

        // When
        $this->terminate($this->request($version));

        // Then : ni écriture, ni erreur, ni journal
        $this->assertSame([], $this->commandBus->dispatched());
        $this->assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableVersions(): iterable
    {
        yield 'vide' => [''];
        yield 'deux nombres' => ['1.2'];
        yield 'quatre nombres' => ['1.2.3.4'];
        yield 'suffixe' => ['0.0.0-dev'];
        yield 'texte' => ['latest'];
        yield 'espace' => [' 0.1.8'];
        yield 'trop long' => ['1.2.' . str_repeat('9', 30)];
        yield 'retour à la ligne' => ["0.1.8\n"];
    }

    #[DataProvider('unknownPlatforms')]
    public function testAnUnknownPlatformIsIgnored(?string $platform): void
    {
        // Given
        $this->authenticate();

        // When
        $this->terminate($this->request('0.1.8', platform: $platform));

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unknownPlatforms(): iterable
    {
        yield 'iOS' => ['ios'];
        yield 'casse' => ['Android'];
        yield 'vide' => [''];
        yield 'absente' => [null];
    }

    public function testWithoutVersionHeaderNothingHappens(): void
    {
        // Given
        $this->authenticate();

        // When
        $this->terminate(Request::create('/api/v1/me'));

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testAFailingWriteNeverReachesTheUserAndIsLoggedWithoutItsMessage(): void
    {
        // Given : l'écriture échoue, avec un message qui porte une donnée
        $this->authenticate();
        $this->commandBus->willThrow(UpdateUserAppVersionCommand::class, new RuntimeException('secret ' . self::USER_ID));
        $request = $this->request('0.1.8');

        // When
        $this->terminate($request);

        // Then : aucune exception, un avertissement avec la classe et l'endroit seulement
        $warnings = $this->logger->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(RuntimeException::class, $warnings[0]);
        $this->assertStringNotContainsString('secret', $warnings[0]);
        $this->assertStringNotContainsString(self::USER_ID, $warnings[0]);
        // Then : rien n'est retenu en mémoire, la version sera reprise plus tard
        $this->assertNull($this->memory->of(self::USER_ID, AppPlatform::ANDROID));
    }

    public function testADisabledAccountWritesNothing(): void
    {
        // Given
        $this->authenticate(disabled: true);

        // When
        $this->terminate($this->request('0.1.8'));

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testAPublicRouteWritesNothing(): void
    {
        // Given : aucun jeton
        // When
        $this->terminate($this->request('0.1.8'));

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testTheAccountComesFromTheTokenNeverFromAHeader(): void
    {
        // Given
        $this->authenticate();
        $request = $this->request('0.1.8');
        $request->headers->set('X-User-Id', '22222222-2222-4222-8222-222222222222');
        $request->headers->set('X-App-User', '22222222-2222-4222-8222-222222222222');

        // When
        $this->terminate($request);

        // Then
        $this->assertEquals([new UpdateUserAppVersionCommand(self::USER_ID, AppPlatform::ANDROID, '0.1.8')], $this->commandBus->dispatched());
    }

    public function testOnlyTheApiIsConcerned(): void
    {
        // Given
        $this->authenticate();

        // When
        $this->terminate($this->request('0.1.8', path: '/api/auth/refresh'));

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testThePutRouteKeepsItsOwnWrite(): void
    {
        // Given
        $this->authenticate();
        $request = $this->request('0.1.8', path: '/api/v1/users/me/app-version');
        $request->attributes->set('_route', UpdateUserAppVersionApiController::ROUTE);

        // When
        $this->terminate($request);

        // Then
        $this->assertSame([], $this->commandBus->dispatched());
    }

    private function authenticate(bool $disabled = false): void
    {
        $user = new SecurityUserDouble(self::USER_ID, disabled: $disabled);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }

    private function request(string $version, ?string $platform = 'android', string $path = '/api/v1/me'): Request
    {
        $request = Request::create($path);
        $request->headers->set(RecordAppVersionOnRequestListener::VERSION_HEADER, $version);
        if ($platform !== null) {
            $request->headers->set(RecordAppVersionOnRequestListener::PLATFORM_HEADER, $platform);
        }

        return $request;
    }

    private function terminate(Request $request): void
    {
        $this->listener->__invoke(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
    }
}

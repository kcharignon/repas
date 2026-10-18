<?php

namespace Repas\Tests\Drive\Infrastructure\Http;


use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\DriveRateLimitListener;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\User\Domain\Model\User;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Le plafond de débit par utilisateur des routes du drive (DRV-615), avec le vrai composant RateLimiter : stockage dans un pool
 * en mémoire (injecté), temps avancé par `RateLimiterTimeTravel` (aucun sommeil). Plafonds de test : 3 écritures, 5 lectures.
 */
class DriveRateLimitListenerTest extends TestCase
{
    private ArrayAdapter $pool;
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->tokens = new TokenStorage();
    }

    public function testTheCallsUpToTheLimitPassAndTheNextOneIsRefusedWithATimeToWait(): void
    {
        // Given
        $this->logIn(new UserBuilder()->build());
        $listener = $this->listener();

        // When : trois écritures passent
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->writeController()));
        }

        // Then : la quatrième est refusée, 429 + Retry-After, message du code métier
        $exception = $this->refusedBy($listener, $this->writeController());
        $this->assertSame(429, $exception->getStatusCode());
        $this->assertSame('DRIVE_RATE_LIMITED', $exception->getMessage());
        $this->assertInstanceOf(DriveException::class, $exception->getPrevious());
        $this->assertSame(['DRIVE_RATE_LIMITED', 429], [$exception->getPrevious()->getMessage(), $exception->getPrevious()->getCode()]);
        $retryAfter = (int) $exception->getHeaders()['Retry-After'];
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function testRetryAfterIsTheDelayGivenByTheLimiterCountedFromTheInjectedClock(): void
    {
        // Given : un limiteur qui refuse en annonçant 17 secondes, une horloge figée
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-18 10:00:00'));
        $refusing = $this->refusingFactory($clock->now()->modify('+17 seconds'));
        $this->logIn(new UserBuilder()->build());
        $listener = new DriveRateLimitListener($refusing, $refusing, $this->tokens, $clock);

        // When
        $exception = $this->refusedBy($listener, $this->writeController());

        // Then
        $this->assertSame(17, $exception->getHeaders()['Retry-After']);
    }

    public function testRetryAfterIsAtLeastOneSecond(): void
    {
        // Given : la date annoncée est déjà passée pour l'horloge injectée
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-18 10:00:00'));
        $past = $this->refusingFactory($clock->now()->modify('-5 seconds'));
        $this->logIn(new UserBuilder()->build());

        // When
        $exception = $this->refusedBy(new DriveRateLimitListener($past, $past, $this->tokens, $clock), $this->writeController());

        // Then
        $this->assertSame(1, $exception->getHeaders()['Retry-After']);
    }

    public function testEachUserHasHisOwnCounter(): void
    {
        // Given : Alice a épuisé ses écritures
        $listener = $this->listener();
        $alice = $this->logIn(new UserBuilder()->build());
        $this->exhaustWrites($listener);
        $this->refusedBy($listener, $this->writeController());

        // When : Bob se connecte, depuis la même adresse
        $this->logIn(new UserBuilder()->build());
        $listener($this->event($this->writeController()));

        // Then : Bob passe ; Alice, elle, est toujours bloquée
        $this->logIn($alice);
        $this->assertSame(429, $this->refusedBy($listener, $this->writeController())->getStatusCode());
    }

    public function testTheWriteAndTheReadCountersAreSeparate(): void
    {
        // Given : les écritures sont épuisées
        $this->logIn(new UserBuilder()->build());
        $listener = $this->listener();
        $this->exhaustWrites($listener);

        // When : cinq lectures passent, la sixième est refusée
        for ($i = 0; $i < 5; $i++) {
            $listener($this->event($this->readController()));
        }

        // Then
        $this->assertSame(429, $this->refusedBy($listener, $this->readController())->getStatusCode());
        $this->assertSame(429, $this->refusedBy($listener, $this->writeController())->getStatusCode());
    }

    public function testTheMarkOnTheMethodAndOnTheClassBothCount(): void
    {
        // Given
        $this->logIn(new UserBuilder()->build());
        $listener = $this->listener();
        $onMethod = new class {
            #[RateLimitedPerUser(DriveRateLimit::WRITE)]
            public function __invoke(): void
            {
            }
        };
        $onClass = new #[RateLimitedPerUser(DriveRateLimit::WRITE)] class {
            public function list(): void
            {
            }
        };

        // When : deux appels par la méthode marquée, un par la classe marquée, même compteur
        $listener($this->event($onMethod));
        $listener($this->event($onMethod));
        $listener($this->event([$onClass, 'list']));

        // Then
        $this->assertSame(429, $this->refusedBy($listener, $onMethod)->getStatusCode());
    }

    public function testAControllerWithoutTheMarkIsNeverCounted(): void
    {
        // Given
        $this->logIn(new UserBuilder()->build());
        $listener = new DriveRateLimitListener($this->factory('drive_write', 1), $this->factory('drive_read', 1), $this->tokens, new FrozenClock());
        $controller = new class {
            public function __invoke(): void
            {
            }
        };

        // When : cent appels
        for ($i = 0; $i < 100; $i++) {
            $listener($this->event($controller));
        }

        // Then : aucun refus, aucun compteur créé dans le cache
        $this->assertSame([], $this->pool->getValues());
    }

    public function testTheCounterIsFreeAgainOnceTheWindowHasPassedAndRefusedCallsDidNotCount(): void
    {
        // Given : le plafond est atteint, puis le client insiste dix fois
        $user = $this->logIn(new UserBuilder()->build());
        $listener = $this->listener();
        $this->exhaustWrites($listener);
        for ($i = 0; $i < 10; $i++) {
            $this->refusedBy($listener, $this->writeController());
        }

        // When : deux minutes passent (une fenêtre glissante se vide deux intervalles après le dernier coup)
        new RateLimiterTimeTravel($this->pool)->advance('drive_write', 'user-' . $user->getId(), 121);

        // Then : les trois écritures repassent, la quatrième est de nouveau refusée
        $this->exhaustWrites($listener);
        $this->assertSame(429, $this->refusedBy($listener, $this->writeController())->getStatusCode());
    }

    public function testWithoutAnAuthenticatedUserTheCounterFallsBackToTheIpAddress(): void
    {
        // Given : aucun jeton (route mal protégée) ; deux adresses
        $listener = $this->listener();
        $fromA = fn(): ControllerEvent => $this->event($this->writeController(), '203.0.113.7');
        $fromB = fn(): ControllerEvent => $this->event($this->writeController(), '203.0.113.8');

        // When : l'adresse A épuise ses trois écritures
        for ($i = 0; $i < 3; $i++) {
            $listener($fromA());
        }

        // Then : A est refusée, B passe ; le compteur d'un utilisateur n'est pas celui de l'adresse
        try {
            $listener($fromA());
            $this->fail('La quatrième écriture de la même adresse aurait dû être refusée.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $listener($fromB());
        $this->logIn(new UserBuilder()->build());
        $listener($this->event($this->writeController(), '203.0.113.7'));
    }

    /**
     * Un limiteur qui refuse toujours, en annonçant la date à laquelle réessayer : pour prouver le calcul de `Retry-After`.
     */
    private function refusingFactory(DateTimeImmutable $retryAfter): RateLimiterFactoryInterface
    {
        $limiter = new class($retryAfter) implements LimiterInterface {
            public function __construct(private readonly DateTimeImmutable $retryAfter)
            {
            }

            public function reserve(int $tokens = 1, ?float $maxTime = null): never
            {
                throw new LogicException('non utilisé');
            }

            public function consume(int $tokens = 1): RateLimit
            {
                return new RateLimit(0, $this->retryAfter, false, 60);
            }

            public function reset(): void
            {
            }
        };

        return new class($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private readonly LimiterInterface $limiter)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };
    }

    private function listener(): DriveRateLimitListener
    {
        return new DriveRateLimitListener($this->factory('drive_write', 3), $this->factory('drive_read', 5), $this->tokens, new FrozenClock());
    }

    private function factory(string $name, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $name, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new CacheStorage($this->pool));
    }

    private function logIn(User $user): User
    {
        $this->tokens->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));

        return $user;
    }

    private function exhaustWrites(DriveRateLimitListener $listener): void
    {
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->writeController()));
        }
    }

    private function refusedBy(DriveRateLimitListener $listener, object $controller): TooManyRequestsHttpException
    {
        try {
            $listener($this->event($controller));
        } catch (TooManyRequestsHttpException $exception) {
            return $exception;
        }

        $this->fail('L\'appel aurait dû être refusé.');
    }

    private function writeController(): object
    {
        return new #[RateLimitedPerUser(DriveRateLimit::WRITE)] class {
            public function __invoke(): void
            {
            }
        };
    }

    private function readController(): object
    {
        return new #[RateLimitedPerUser(DriveRateLimit::READ)] class {
            public function __invoke(): void
            {
            }
        };
    }

    private function event(callable $controller, string $ip = '127.0.0.1'): ControllerEvent
    {
        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller,
            Request::create('/api/v1/drive/anything', server: ['REMOTE_ADDR' => $ip]),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

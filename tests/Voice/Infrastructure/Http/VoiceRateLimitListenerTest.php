<?php

namespace Repas\Tests\Voice\Infrastructure\Http;


use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\User\Domain\Model\User;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Infrastructure\Http\VoiceRateLimit;
use Repas\Voice\Infrastructure\Http\VoiceRateLimited;
use Repas\Voice\Infrastructure\Http\VoiceRateLimitListener;
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
 * Les plafonds de débit de la liaison de compte (VOX-202), avec le vrai composant RateLimiter : stockage dans un pool en mémoire
 * (injecté), temps avancé par `RateLimiterTimeTravel` (aucun sommeil). Plafonds de test : 3 échanges de jetons, 2 autorisations.
 */
class VoiceRateLimitListenerTest extends TestCase
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
        $listener = $this->listener();

        // When : trois échanges de jetons passent
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->tokenController()));
        }

        // Then : le quatrième est refusé, 429 + Retry-After, message du code métier
        $exception = $this->refusedBy($listener, $this->tokenController());
        $this->assertSame(429, $exception->getStatusCode());
        $this->assertSame('VOICE_RATE_LIMITED', $exception->getMessage());
        $this->assertInstanceOf(VoiceException::class, $exception->getPrevious());
        $this->assertSame([VoiceException::RATE_LIMITED, 429], [$exception->getPrevious()->getMessage(), $exception->getPrevious()->getCode()]);
        $retryAfter = (int) $exception->getHeaders()['Retry-After'];
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(3600, $retryAfter);
    }

    public function testRetryAfterIsTheDelayGivenByTheLimiterCountedFromTheInjectedClock(): void
    {
        // Given : un limiteur qui refuse en annonçant 17 secondes, une horloge figée
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-25 10:00:00'));
        $refusing = $this->refusingFactory($clock->now()->modify('+17 seconds'));
        $listener = new VoiceRateLimitListener($refusing, $refusing, $this->tokens, $clock);

        // When
        $exception = $this->refusedBy($listener, $this->tokenController());

        // Then
        $this->assertSame(17, $exception->getHeaders()['Retry-After']);
    }

    public function testRetryAfterIsAtLeastOneSecond(): void
    {
        // Given : la date annoncée est déjà passée pour l'horloge injectée
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-25 10:00:00'));
        $past = $this->refusingFactory($clock->now()->modify('-5 seconds'));

        // When
        $exception = $this->refusedBy(new VoiceRateLimitListener($past, $past, $this->tokens, $clock), $this->tokenController());

        // Then
        $this->assertSame(1, $exception->getHeaders()['Retry-After']);
    }

    public function testTheTokenCounterIsPerIpAddressWhoeverIsLoggedIn(): void
    {
        // Given : une adresse a épuisé ses échanges, même avec un utilisateur connecté
        $listener = $this->listener();
        $this->logIn(new UserBuilder()->build());
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->tokenController(), '203.0.113.7'));
        }

        // When / Then : la même adresse est refusée sous un autre utilisateur, une autre adresse passe
        $this->logIn(new UserBuilder()->build());
        $this->assertSame(429, $this->refusedBy($listener, $this->tokenController(), '203.0.113.7')->getStatusCode());
        $listener($this->event($this->tokenController(), '203.0.113.8'));
    }

    public function testTheAuthorizeCounterIsPerUserNotPerIpAddress(): void
    {
        // Given : Alice a épuisé ses deux autorisations
        $listener = $this->listener();
        $alice = $this->logIn(new UserBuilder()->build());
        for ($i = 0; $i < 2; $i++) {
            $listener($this->event($this->authorizeController(), '203.0.113.7'));
        }
        $this->assertSame(429, $this->refusedBy($listener, $this->authorizeController(), '203.0.113.7')->getStatusCode());

        // When : Bob, depuis la même adresse
        $this->logIn(new UserBuilder()->build());
        $listener($this->event($this->authorizeController(), '203.0.113.7'));

        // Then : Bob passe ; Alice est toujours bloquée, même depuis une autre adresse
        $this->logIn($alice);
        $this->assertSame(429, $this->refusedBy($listener, $this->authorizeController(), '203.0.113.99')->getStatusCode());
    }

    public function testTheTwoCountersAreSeparate(): void
    {
        // Given : les échanges de jetons de l'adresse sont épuisés
        $listener = $this->listener();
        $this->logIn(new UserBuilder()->build());
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->tokenController()));
        }

        // When / Then : une autorisation passe encore
        $listener($this->event($this->authorizeController()));
        $this->assertSame(429, $this->refusedBy($listener, $this->tokenController())->getStatusCode());
    }

    public function testAnAuthorizeCallWithoutAnAuthenticatedUserFallsBackToTheIpAddress(): void
    {
        // Given : aucun jeton (route mal protégée)
        $listener = $this->listener();

        // When
        for ($i = 0; $i < 2; $i++) {
            $listener($this->event($this->authorizeController(), '203.0.113.7'));
        }

        // Then : le compteur est celui de l'adresse
        $this->assertSame(429, $this->refusedBy($listener, $this->authorizeController(), '203.0.113.7')->getStatusCode());
        $listener($this->event($this->authorizeController(), '203.0.113.8'));
    }

    public function testTheCounterIsFreeAgainOnceTheWindowHasPassedAndRefusedCallsDidNotCount(): void
    {
        // Given : le plafond est atteint, puis le client insiste dix fois
        $listener = $this->listener();
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->tokenController(), '203.0.113.7'));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->refusedBy($listener, $this->tokenController(), '203.0.113.7');
        }

        // When : deux heures passent (une fenêtre glissante se vide deux intervalles après le dernier coup)
        new RateLimiterTimeTravel($this->pool)->advance('voice_token', 'ip-203.0.113.7', 2 * 3600 + 1);

        // Then : les trois échanges repassent, le quatrième est de nouveau refusé
        for ($i = 0; $i < 3; $i++) {
            $listener($this->event($this->tokenController(), '203.0.113.7'));
        }
        $this->assertSame(429, $this->refusedBy($listener, $this->tokenController(), '203.0.113.7')->getStatusCode());
    }

    public function testTheMarkOnTheMethodAndOnTheClassBothCount(): void
    {
        // Given
        $listener = $this->listener();
        $onMethod = new class {
            #[VoiceRateLimited(VoiceRateLimit::AUTHORIZE)]
            public function __invoke(): void
            {
            }
        };
        $onClass = new #[VoiceRateLimited(VoiceRateLimit::AUTHORIZE)] class {
            public function list(): void
            {
            }
        };

        // When : un appel par la méthode marquée, un par la classe marquée, même compteur (deux au plus)
        $listener($this->event($onMethod));
        $listener($this->event([$onClass, 'list']));

        // Then
        $this->assertSame(429, $this->refusedBy($listener, $onMethod)->getStatusCode());
    }

    public function testAControllerWithoutTheMarkIsNeverCounted(): void
    {
        // Given
        $listener = new VoiceRateLimitListener($this->factory('voice_token', 1), $this->factory('voice_authorize', 1), $this->tokens, new FrozenClock());
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

    public function testEachPerUserLimitIsDeclaredAsSuch(): void
    {
        $this->assertFalse(VoiceRateLimit::TOKEN->isPerUser());
        $this->assertTrue(VoiceRateLimit::AUTHORIZE->isPerUser());
        $this->assertSame(['voice_token', 'voice_authorize'], [VoiceRateLimit::TOKEN->value, VoiceRateLimit::AUTHORIZE->value]);
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

    private function listener(): VoiceRateLimitListener
    {
        return new VoiceRateLimitListener($this->factory('voice_token', 3), $this->factory('voice_authorize', 2), $this->tokens, new FrozenClock());
    }

    private function factory(string $name, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $name, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'], new CacheStorage($this->pool));
    }

    private function logIn(User $user): User
    {
        $this->tokens->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));

        return $user;
    }

    private function refusedBy(VoiceRateLimitListener $listener, object $controller, string $ip = '127.0.0.1'): TooManyRequestsHttpException
    {
        try {
            $listener($this->event($controller, $ip));
        } catch (TooManyRequestsHttpException $exception) {
            return $exception;
        }

        $this->fail('L\'appel aurait dû être refusé.');
    }

    private function tokenController(): object
    {
        return new #[VoiceRateLimited(VoiceRateLimit::TOKEN)] class {
            public function __invoke(): void
            {
            }
        };
    }

    private function authorizeController(): object
    {
        return new #[VoiceRateLimited(VoiceRateLimit::AUTHORIZE)] class {
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
            Request::create('/api/voice/oauth/token', server: ['REMOTE_ADDR' => $ip]),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

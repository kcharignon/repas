<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\Security\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Les trois compteurs d'échecs de connexion, avec les chiffres de config/packages/rate_limiter.yaml : 25 par adresse, 5 par adresse
 * et e-mail (15 minutes), 20 par e-mail puis un toutes les 3 minutes. Le test rejoue ce que fait le `LoginThrottlingListener` de Symfony :
 * `peek` avant la vérification du mot de passe, `consume` à chaque échec, `reset` à chaque réussite.
 */
class LoginRateLimiterTest extends TestCase
{
    private LoginRateLimiter $limiter;

    protected function setUp(): void
    {
        $storage = new InMemoryStorage();
        $this->limiter = new LoginRateLimiter(
            new RateLimiterFactory(['id' => 'login_address', 'policy' => 'fixed_window', 'limit' => 25, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'login_address_email', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'login_email', 'policy' => 'token_bucket', 'limit' => 20, 'rate' => ['interval' => '3 minutes', 'amount' => 1]], $storage),
            'une-cle-de-test',
        );
    }

    public function testTheSixthFailureOfTheSameAddressAndEmailIsRefused(): void
    {
        // When
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 5);

        // Then
        $this->assertTrue($this->isBlocked('203.0.113.7', 'alexiane@example.com'));
    }

    public function testAnotherEmailFromTheSameAddressIsNotBlockedByTheFiveFailures(): void
    {
        // When
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 5);

        // Then
        $this->assertFalse($this->isBlocked('203.0.113.7', 'autre@example.com'));
    }

    public function testAnotherAddressHasItsOwnFiveFailuresForTheSameEmail(): void
    {
        // When
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 5);

        // Then
        $this->assertTrue($this->isBlocked('203.0.113.7', 'alexiane@example.com'));
        $this->assertFalse($this->isBlocked('203.0.113.8', 'alexiane@example.com'));
    }

    public function testTwoAddressesOfTheSameIpv6PrefixShareTheirCounter(): void
    {
        // When : cinq échecs depuis cinq adresses du même /64
        for ($i = 1; $i <= 5; $i++) {
            $this->failedLogin(sprintf('2001:db8:1:2::%x', $i), 'alexiane@example.com');
        }

        // Then : une sixième adresse de ce préfixe est refusée, un autre préfixe ne l'est pas
        $this->assertTrue($this->isBlocked('2001:db8:1:2:aaaa:bbbb:cccc:dddd', 'alexiane@example.com'));
        $this->assertFalse($this->isBlocked('2001:db8:1:3::1', 'alexiane@example.com'));
    }

    public function testAnIpv4AndItsMappedIpv6FormShareTheirCounter(): void
    {
        // When
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 5);

        // Then
        $this->assertTrue($this->isBlocked('::ffff:203.0.113.7', 'alexiane@example.com'));
    }

    public function testTheCaseAndTheSpacesAroundTheEmailChangeNothing(): void
    {
        // When
        $this->failedLogin('203.0.113.7', 'Alexiane@Example.com', 2);
        $this->failedLogin('203.0.113.7', ' alexiane@example.com ', 3);

        // Then
        $this->assertTrue($this->isBlocked('203.0.113.7', 'ALEXIANE@EXAMPLE.COM'));
    }

    public function testTheTwentyFirstFailureOfAnEmailIsRefusedWhateverTheAddress(): void
    {
        // When : vingt échecs depuis vingt adresses différentes (un seul échec chacune)
        for ($i = 1; $i <= 20; $i++) {
            $this->failedLogin("203.0.113.$i", 'alexiane@example.com');
        }

        // Then : une adresse inconnue est refusée à son tour, avec une attente de trois minutes au plus
        $limit = $this->limiter->peek($this->request('198.51.100.1', 'alexiane@example.com'));
        $this->assertSame(0, $limit->getRemainingTokens());
        $this->assertLessThanOrEqual(180, $limit->getRetryAfter()->getTimestamp() - time());
        $this->assertGreaterThan(0, $limit->getRetryAfter()->getTimestamp() - time());
        $this->assertFalse($this->isBlocked('198.51.100.1', 'autre@example.com'));
    }

    public function testAnUnknownEmailIsCountedLikeAKnownOne(): void
    {
        // When : le limiteur ne lit rien en base, une adresse qui n'existe pas est comptée pareil
        for ($i = 1; $i <= 20; $i++) {
            $this->failedLogin("203.0.113.$i", 'personne@example.com');
        }

        // Then
        $this->assertTrue($this->isBlocked('198.51.100.1', 'personne@example.com'));
    }

    public function testTheTwentySixthEmailTriedFromOneAddressIsRefused(): void
    {
        // When : vingt-cinq adresses e-mail différentes depuis la même adresse
        for ($i = 1; $i <= 25; $i++) {
            $this->failedLogin('203.0.113.7', "personne$i@example.com");
        }

        // Then : une vingt-sixième, jamais vue, est refusée ; une autre adresse non
        $this->assertTrue($this->isBlocked('203.0.113.7', 'nouvelle@example.com'));
        $this->assertFalse($this->isBlocked('203.0.113.8', 'nouvelle@example.com'));
    }

    public function testRefusedAttemptsConsumeNothing(): void
    {
        // Given : cinq échecs, puis cinquante tentatives refusées de la même adresse
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 5);
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 50);

        // When : quatorze autres adresses échouent
        for ($i = 1; $i <= 14; $i++) {
            $this->failedLogin("198.51.100.$i", 'alexiane@example.com');
        }

        // Then : l'e-mail n'a compté que les cinq échecs de départ (dix-neuf en tout), il lui en reste un
        $this->assertFalse($this->isBlocked('192.0.2.1', 'alexiane@example.com'));
        $this->failedLogin('192.0.2.1', 'alexiane@example.com');
        $this->assertTrue($this->isBlocked('192.0.2.2', 'alexiane@example.com'));
    }

    public function testASuccessfulLoginClearsTheCounterOfItsAddressAndEmailOnly(): void
    {
        // Given : quatre échecs
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 4);

        // When : une réussite
        $this->limiter->reset($this->request('203.0.113.7', 'alexiane@example.com'));

        // Then : cinq nouveaux échecs sont permis pour ce couple
        $this->failedLogin('203.0.113.7', 'alexiane@example.com', 4);
        $this->assertFalse($this->isBlocked('203.0.113.7', 'alexiane@example.com'));
        $this->failedLogin('203.0.113.7', 'alexiane@example.com');
        $this->assertTrue($this->isBlocked('203.0.113.7', 'alexiane@example.com'));

        // Then : mais le compteur de l'e-mail n'a pas été remis à zéro (neuf échecs comptés) : dix de plus le laissent ouvert, le onzième le ferme
        for ($i = 1; $i <= 10; $i++) {
            $this->failedLogin("198.51.100.$i", 'alexiane@example.com');
        }
        $this->assertFalse($this->isBlocked('192.0.2.1', 'alexiane@example.com'));
        $this->failedLogin('192.0.2.1', 'alexiane@example.com');
        $this->assertTrue($this->isBlocked('192.0.2.2', 'alexiane@example.com'));
    }

    public function testNoEmailOrAddressIsReadableInTheStoredKeys(): void
    {
        // Given
        $storage = new class extends InMemoryStorage {
            /** @var list<string> */
            public array $savedIds = [];

            public function save(LimiterStateInterface $limiterState): void
            {
                $this->savedIds[] = $limiterState->getId();
                parent::save($limiterState);
            }
        };
        $limiter = new LoginRateLimiter(
            new RateLimiterFactory(['id' => 'login_address', 'policy' => 'fixed_window', 'limit' => 25, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'login_address_email', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'login_email', 'policy' => 'token_bucket', 'limit' => 20, 'rate' => ['interval' => '3 minutes', 'amount' => 1]], $storage),
            'une-cle-de-test',
        );

        // When
        $limiter->consume($this->request('203.0.113.7', 'alexiane@example.com'));

        // Then
        $this->assertCount(3, $storage->savedIds);
        $this->assertStringNotContainsString('alexiane', implode(' ', $storage->savedIds));
        $this->assertStringNotContainsString('203.0.113.7', implode(' ', $storage->savedIds));
    }

    private function failedLogin(string $address, string $email, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->limiter->consume($this->request($address, $email));
        }
    }

    /**
     * Ce que décide le listener de Symfony avant de vérifier le mot de passe : un compteur sans jeton restant refuse la connexion.
     */
    private function isBlocked(string $address, string $email): bool
    {
        $limit = $this->limiter->peek($this->request($address, $email));

        return !$limit->isAccepted() || $limit->getRemainingTokens() === 0;
    }

    private function request(string $address, string $email): Request
    {
        $request = Request::create('/api/login_check', 'POST', server: ['REMOTE_ADDR' => $address]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return $request;
    }
}

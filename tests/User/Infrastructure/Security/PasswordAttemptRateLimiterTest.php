<?php

namespace Repas\Tests\User\Infrastructure\Security;


use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Infrastructure\Security\PasswordAttemptRateLimiter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Le plafond des mots de passe refusés (5 par 15 minutes et par utilisateur, config/packages/rate_limiter.yaml), avec le vrai composant
 * RateLimiter et un stockage en mémoire : un mot de passe juste ne consomme rien, seul un refus compte, et rien n'est comparé au-delà du
 * plafond. Plusieurs processus à la fois : tests/Shared/Infrastructure/RateLimiter.
 */
class PasswordAttemptRateLimiterTest extends TestCase
{
    private PasswordAttemptRateLimiter $limiter;
    private LockFactory $locks;

    /** Nombre de comparaisons réellement faites. */
    private int $compared = 0;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->limiter = new PasswordAttemptRateLimiter(
            new RateLimiterFactory(['id' => 'password_check', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage()),
            $this->locks,
        );
    }

    public function testARightPasswordNeverConsumesAnything(): void
    {
        // When : cinquante mots de passe justes
        for ($i = 1; $i <= 50; $i++) {
            $this->assertTrue($this->limiter->attempt('user-1', $this->comparison(true)));
        }

        // Then : les cinq refus sont encore permis
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($this->limiter->attempt('user-1', $this->comparison(false)));
        }
        $this->assertSame(55, $this->compared);
    }

    public function testAfterFiveRefusedPasswordsTheUserIsRefusedWithATimeToWaitAndNothingIsCompared(): void
    {
        // Given
        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->attempt('user-1', $this->comparison(false));
        }
        $this->assertSame(5, $this->compared);

        // When
        try {
            $this->limiter->attempt('user-1', $this->comparison(true));
            $this->fail('Le sixième essai aurait dû être refusé.');
        } catch (RateLimitedException $exception) {
            // Then : le code métier, 429, un délai d'au plus 15 minutes, et le bon mot de passe n'est pas comparé
            $this->assertSame(UserException::PASSWORD_RATE_LIMITED, $exception->getMessage());
            $this->assertSame(429, $exception->getCode());
            $this->assertGreaterThanOrEqual(1, $exception->retryAfter());
            $this->assertLessThanOrEqual(901, $exception->retryAfter());
            $this->assertSame(5, $this->compared);
        }
    }

    public function testEachUserHasHisOwnCount(): void
    {
        // Given
        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->attempt('user-1', $this->comparison(false));
        }

        // When / Then : l'autre utilisateur n'est pas touché
        $this->assertTrue($this->limiter->attempt('user-2', $this->comparison(true)));
        $this->expectException(RateLimitedException::class);
        $this->limiter->attempt('user-1', $this->comparison(true));
    }

    public function testTheUserLockIsReleasedAfterEachAttemptWhateverHappens(): void
    {
        // Given : une comparaison qui échoue, puis un plafond atteint
        try {
            $this->limiter->attempt('user-1', static fn(): bool => throw new RuntimeException('panne'));
        } catch (Throwable) {
        }
        $free = $this->locks->createLock('password-check-user-1');
        $this->assertTrue($free->acquire(), 'verrou rendu après une comparaison qui échoue');
        $free->release();

        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->attempt('user-1', $this->comparison(false));
        }
        try {
            $this->limiter->attempt('user-1', $this->comparison(true));
        } catch (RateLimitedException) {
        }

        // Then
        $free = $this->locks->createLock('password-check-user-1');
        $this->assertTrue($free->acquire(), 'verrou rendu après un refus du plafond');
        $free->release();
    }

    /**
     * @return Closure(): bool
     */
    private function comparison(bool $result): Closure
    {
        return function () use ($result): bool {
            $this->compared++;

            return $result;
        };
    }
}

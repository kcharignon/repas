<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Infrastructure\Security\PasswordAttemptRateLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Le plafond des mots de passe refusés (5 par 15 minutes et par utilisateur, config/packages/rate_limiter.yaml), avec le vrai composant
 * RateLimiter et un stockage en mémoire : interroger ne consomme rien, seul un refus noté compte.
 */
class PasswordAttemptRateLimiterTest extends TestCase
{
    private PasswordAttemptRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new PasswordAttemptRateLimiter(
            new RateLimiterFactory(['id' => 'password_check', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage()),
        );
    }

    public function testAskingNeverConsumesAnything(): void
    {
        // When : cinquante questions sans aucun refus noté
        for ($i = 1; $i <= 50; $i++) {
            $this->limiter->assertAllowed('user-1');
        }

        // Then : les cinq refus sont encore permis
        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->assertAllowed('user-1');
            $this->limiter->recordFailure('user-1');
        }
        $this->addToAssertionCount(1);
    }

    public function testAfterFiveRecordedFailuresTheUserIsRefusedWithATimeToWait(): void
    {
        // Given
        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->assertAllowed('user-1');
            $this->limiter->recordFailure('user-1');
        }

        // When
        try {
            $this->limiter->assertAllowed('user-1');
            $this->fail('Le sixième essai aurait dû être refusé.');
        } catch (RateLimitedException $exception) {
            // Then : le code métier, 429, et un délai d'au plus 15 minutes
            $this->assertSame(UserException::PASSWORD_RATE_LIMITED, $exception->getMessage());
            $this->assertSame(429, $exception->getCode());
            $this->assertGreaterThanOrEqual(1, $exception->retryAfter());
            $this->assertLessThanOrEqual(900, $exception->retryAfter());
        }
    }

    public function testEachUserHasHisOwnCount(): void
    {
        // Given
        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->recordFailure('user-1');
        }

        // When / Then : l'autre utilisateur n'est pas touché
        $this->limiter->assertAllowed('user-2');
        $this->expectException(RateLimitedException::class);
        $this->limiter->assertAllowed('user-1');
    }
}

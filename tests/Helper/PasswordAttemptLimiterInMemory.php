<?php

namespace Repas\Tests\Helper;


use Closure;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\PasswordAttemptLimiter;

/**
 * Plafond des mots de passe refusés, en mémoire : `new PasswordAttemptLimiterInMemory(3)` refuse dès le troisième échec noté. Enregistre
 * ses appels (`asked`, `failures`) pour vérifier qu'un cas d'usage interroge puis note, et ne note rien pour un mot de passe juste.
 */
class PasswordAttemptLimiterInMemory implements PasswordAttemptLimiter
{
    /** @var list<string> */
    public array $asked = [];
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(private readonly int $limit = 5, private readonly int $retryAfter = 600)
    {
    }

    public function attempt(string $userId, Closure $matches): bool
    {
        $this->asked[] = $userId;
        if (($this->failures[$userId] ?? 0) >= $this->limit) {
            throw RateLimitedException::after(UserException::PASSWORD_RATE_LIMITED, $this->retryAfter);
        }

        if ($matches()) {
            return true;
        }

        $this->failures[$userId] = ($this->failures[$userId] ?? 0) + 1;

        return false;
    }

    /**
     * Note un refus fait ailleurs (l'état d'un autre compte déjà au plafond).
     */
    public function recordFailure(string $userId): void
    {
        $this->failures[$userId] = ($this->failures[$userId] ?? 0) + 1;
    }
}

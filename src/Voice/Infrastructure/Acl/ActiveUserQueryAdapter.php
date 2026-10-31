<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\User\Application\OpenHostService\UserAccountQueryOHS;
use Repas\Voice\Application\Port\ActiveUserQueryPort;

/**
 * Couche anticorruption (ADR 0035) : le compte publié par `User` devient la réponse que la lecture vocale attend.
 */
final readonly class ActiveUserQueryAdapter implements ActiveUserQueryPort
{
    public function __construct(
        private UserAccountQueryOHS $accounts,
    ) {
    }

    public function isActive(string $userId): bool
    {
        return $this->accounts->account($userId)->active;
    }
}

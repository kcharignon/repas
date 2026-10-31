<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\SeedingAccountCommandPort;
use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\User\Domain\Exception\UserException;

/**
 * Couche anticorruption (ADR 0035) : le compte (modèle de `User`) devient ce que l'amorçage des ingrédients attend, son existence et
 * l'empreinte de ses statistiques.
 */
final readonly class SeedingAccountCommandAdapter implements SeedingAccountCommandPort
{
    public function __construct(
        private UserAccountCommandOHS $accounts,
    ) {
    }

    public function exists(string $userId): bool
    {
        try {
            $this->accounts->find($userId);
        } catch (UserException) {
            return false;
        }

        return true;
    }

    public function statisticsFingerprint(string $userId): string
    {
        try {
            return json_encode($this->accounts->find($userId)->getStatistics(), JSON_THROW_ON_ERROR);
        } catch (UserException) {
            return '';
        }
    }
}

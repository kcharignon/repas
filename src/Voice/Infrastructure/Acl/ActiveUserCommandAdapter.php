<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\User\Domain\Exception\UserException;
use Repas\Voice\Application\Port\ActiveUserCommandPort;

/**
 * Couche anticorruption (ADR 0035) : le compte (modèle de `User`) devient la réponse que les écritures vocales attendent. Un compte
 * inconnu (supprimé) n'est pas actif.
 */
final readonly class ActiveUserCommandAdapter implements ActiveUserCommandPort
{
    public function __construct(
        private UserAccountCommandOHS $accounts,
    ) {
    }

    public function isActive(string $userId): bool
    {
        try {
            return $this->accounts->find($userId)->isActive();
        } catch (UserException) {
            return false;
        }
    }
}

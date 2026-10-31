<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\DefaultServingCommandPort;
use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\User\Domain\Exception\UserException;

/**
 * Couche anticorruption (ADR 0035) : le compte (modèle de `User`) devient le nombre de personnes que la planification vocale attend. Un
 * compte inconnu, ou un nombre qui n'en est pas un, donne le nombre habituel.
 */
final readonly class DefaultServingCommandAdapter implements DefaultServingCommandPort
{
    public function __construct(
        private UserAccountCommandOHS $accounts,
    ) {
    }

    public function of(string $userId): int
    {
        try {
            $serving = $this->accounts->find($userId)->getDefaultServing();
        } catch (UserException) {
            return self::FALLBACK;
        }

        return $serving > 0 ? $serving : self::FALLBACK;
    }
}

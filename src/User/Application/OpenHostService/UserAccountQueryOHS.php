<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Application\Loader\UserLoader;

/**
 * Open Host Service de lecture du contexte `User` (ADR 0035) : l'état d'un compte pour un autre contexte qui lit. Un identifiant inconnu
 * (compte supprimé) est un compte qui n'est pas actif.
 */
final readonly class UserAccountQueryOHS
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    public function account(string $userId): UserAccountDTO
    {
        return new UserAccountDTO($userId, $this->userLoader->isActive($userId));
    }
}

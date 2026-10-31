<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

/**
 * Open Host Service d'écriture du contexte `User` (ADR 0035) : le compte lui-même, pour un autre contexte qui écrit et doit savoir à qui
 * il a affaire (un compte désactivé ne peut plus rien, ADR 0021).
 */
final readonly class UserAccountCommandOHS
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @throws UserException compte inconnu
     */
    public function find(string $userId): User
    {
        return $this->users->findOneById($userId);
    }
}

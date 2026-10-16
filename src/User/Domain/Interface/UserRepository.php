<?php

namespace Repas\User\Domain\Interface;

use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User;

interface UserRepository
{
    /**
     * @throws UserException
     */
    public function findOneById(string $id): User;

    /**
     * @throws UserException
     */
    public function findOneByEmail(string $email): User;

    /**
     * Un utilisateur porte cet email (l'email est unique : deux inscriptions du même email se télescoperaient).
     */
    public function emailExists(string $email): bool;

    /**
     * @return Tab<User>
     */
    public function findAll(): Tab;

    public function save(User $user): void;

    /**
     * Supprime le compte lui-même ; ses jetons et ses données du contexte `Repas` partent avec lui (ADR 0021).
     */
    public function delete(User $user): void;
}

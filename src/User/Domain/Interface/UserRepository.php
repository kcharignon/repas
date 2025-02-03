<?php

namespace Repas\User\Domain\Interface;

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

    public function save(User $user): void;
}

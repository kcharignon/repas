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
     * @return Tab<User>
     */
    public function findAll(): Tab;

    public function save(User $user): void;
}

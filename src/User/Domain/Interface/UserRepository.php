<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\User;

interface UserRepository
{
    public function findOneById(string $id): User;

    public function findOneByEmail(string $email): User;

    public function save(User $user): void;
}

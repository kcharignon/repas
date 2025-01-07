<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\User;

interface UserRepository
{
    public function getUserByEmail(string $email): User;
}

<?php

namespace Repas\User\Application\RegisterNewUser;


readonly class RegisterNewUserCommand
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $passwordPlainText,
        public int $defaultServing,
    ) {
    }
}

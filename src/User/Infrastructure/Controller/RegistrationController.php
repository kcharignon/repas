<?php

namespace Repas\User\Infrastructure\Controller;


use Builder\UserBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationController
{
    public function __invoke(UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = (new UserBuilder)->build();
        $plaintextPassword = "test";

        $hashedPassword = $passwordHasher->hashPassword($user, $plaintextPassword);
        $user->setPassword($hashedPassword);

        return new RedirectResponse('/login', 302);
    }

}

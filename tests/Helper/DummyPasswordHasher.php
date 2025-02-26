<?php

namespace Repas\Tests\Helper;


use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class DummyPasswordHasher implements PasswordHasherFactoryInterface, PasswordHasherInterface
{
    public function getPasswordHasher(PasswordHasherAwareInterface|string|PasswordAuthenticatedUserInterface $user): PasswordHasherInterface
    {
        return $this;
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return "hash{$plainPassword}hash";
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $hashedPassword === $this->hash($plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return !str_starts_with($hashedPassword, 'hash') || !str_ends_with($hashedPassword, 'hash');
    }
}

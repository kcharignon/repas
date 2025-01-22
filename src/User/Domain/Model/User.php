<?php

namespace Repas\User\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface, PasswordUpgraderInterface, ModelInterface
{

    use ModelTrait;

    private function __construct(
        private string $id,
        private string $email,
        private array $roles,
        private string $password,
    ) {
    }

    public static function create(string $id, string $email, array $roles, string $password): static
    {
        return new static(
            $id,
            $email,
            $roles,
            $password,
        );
    }

    public static function load(array $datas): static
    {
        return new static(
            $datas['id'],
            $datas['email'],
            $datas['roles'],
            $datas['password'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
        // TODO: Implement eraseCredentials() method.
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->setPassword($newHashedPassword);
    }
}

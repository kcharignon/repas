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
        private int $defaultServing
    ) {
    }

    public static function create(
        string $id,
        string $email,
        array $roles,
        string $password,
        int $defaultServing
    ): static {
        return new static(
            $id,
            $email,
            $roles,
            $password,
            $defaultServing
        );
    }

    public static function load(array $datas): static
    {
        return new static(
            $datas['id'],
            $datas['email'],
            $datas['roles'],
            $datas['password'],
            $datas['default_serving'],
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

    public function getDefaultServing(): int
    {
        return $this->defaultServing;
    }

    public function setDefaultServing(int $defaultServing): User
    {
        $this->defaultServing = $defaultServing;
        return $this;
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

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles ?? [], true);
    }

    public function update(string $defaultServing): void
    {
        $this->defaultServing = $defaultServing;
    }

    public function passwordMatch(string $password): bool
    {
        return password_verify($password, $this->password);
    }
}

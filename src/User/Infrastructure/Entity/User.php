<?php

namespace Repas\User\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Model\User as UserModel;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column( nullable: false)]
    private ?int $defaultServing = null;

    public function __construct(
        string $id,
        string $email,
        string $password,
        array $roles,
        int $defaultServing,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->password = $password;
        $this->roles = $roles;
        $this->defaultServing = $defaultServing;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getDefaultServing(): ?int
    {
        return $this->defaultServing;
    }

    public function setDefaultServing(?int $defaultServing): User
    {
        $this->defaultServing = $defaultServing;
        return $this;
    }

    public static function fromModel(UserModel $user): static
    {
        return new self(
            id: $user->getId(),
            email: $user->getEmail(),
            password: $user->getPassword(),
            roles: $user->getRoles(),
            defaultServing: $user->getDefaultServing(),
        );
    }

    public function updateFromModel(UserModel $user): void
    {
        $this->id = $user->getId();
        $this->email = $user->getEmail();
        $this->password = $user->getPassword();
        $this->roles = $user->getRoles();
        $this->defaultServing = $user->getDefaultServing();
    }
}

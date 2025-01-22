<?php

namespace Repas\User\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User as UserModel;

#[ORM\Entity(repositoryClass: UserRepository::class)]
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

    public function __construct(
        string $id,
        string $email,
        string $password,
        array $roles = [],
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->password = $password;
        $this->roles = $roles;
    }

    public static function fromData(array $datas): static
    {
        return new self($datas['id'], $datas['email'], $datas['password'], $datas['roles']);
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

    public function getModel(): UserModel
    {
        assert(null !== $this->email);
        assert(null !== $this->password);

        return UserModel::load([
            'id' => $this->id,
            'email' => $this->email,
            'password' => $this->password,
            'roles' => $this->roles ?? [],
        ]);
    }

    public static function fromModel(UserModel $user): static
    {
        $datas = $user->toArray();
        return new self(
            id: $datas['id'],
            email: $datas['email'],
            password: $datas['password'],
            roles: $datas['roles'],
        );
    }

    public function updateFromModel(UserModel $user): void
    {
        $this->id = $user->getId();
        $this->email = $user->getEmail();
        $this->password = $user->getPassword();
        $this->roles = $user->getRoles();
    }
}

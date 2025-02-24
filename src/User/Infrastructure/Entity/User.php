<?php

namespace Repas\User\Infrastructure\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Repas\User\Domain\Model\UserStatus as Status;
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

    #[ORM\Column(type: 'string', enumType: Status::class)]
    private ?Status $status = null;

    #[ORM\Column(name: 'statistics', type: 'json', nullable: false)]
    private ?array $statistics = [];

    public function __construct(
        string $id,
        string $email,
        string $password,
        array $roles,
        int $defaultServing,
        Status $status,
        array $statistics,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->password = $password;
        $this->roles = $roles;
        $this->defaultServing = $defaultServing;
        $this->status = $status;
        $this->statistics = $statistics;
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

    public function getStatus(): ?Status
    {
        return $this->status;
    }

    public function setStatus(?Status $status): User
    {
        $this->status = $status;
        return $this;
    }

    public function getStatistics(): array
    {
        return $this->convertDateTimeArray($this->statistics ?? []);
    }

    public function setStatistics(array $statistics): User
    {
        $this->statistics = $statistics;
        return $this;
    }

    private function convertDateTimeArray(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Vérifie si l'array correspond à une structure DateTime
                if (isset($value['date'], $value['timezone_type'], $value['timezone'])) {
                    try {
                        $data[$key] = new DateTimeImmutable($value['date'], new DateTimeZone($value['timezone']));
                    } catch (Exception) {
                        // En cas d'erreur, on garde la valeur inchangée
                    }
                } else {
                    // Applique récursivement la fonction
                    $data[$key] = $this->convertDateTimeArray($value);
                }
            }
        }
        return $data;
    }

    public static function fromModel(UserModel $user): static
    {
        return new self(
            id: $user->getId(),
            email: $user->getEmail(),
            password: $user->getPassword(),
            roles: $user->getRoles(),
            defaultServing: $user->getDefaultServing(),
            status: $user->getStatus(),
            statistics: $user->getStatistics(),
        );
    }

    public function updateFromModel(UserModel $user): void
    {
        $this->id = $user->getId();
        $this->email = $user->getEmail();
        $this->password = $user->getPassword();
        $this->roles = $user->getRoles();
        $this->defaultServing = $user->getDefaultServing();
        $this->status = $user->getStatus();
        $this->statistics = $user->getStatistics();
    }
}

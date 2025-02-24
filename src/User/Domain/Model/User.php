<?php

namespace Repas\User\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Repas\User\Domain\Model\UserStatus as Status;

class User implements UserInterface, PasswordAuthenticatedUserInterface, PasswordUpgraderInterface, ModelInterface
{

    use ModelTrait;

    private function __construct(
        private string $id,
        private string $email,
        private array  $roles,
        private string $password,
        private int    $defaultServing,
        private Status $status,
        private array  $statistics,
    ) {
    }

    public static function create(
        string $id,
        string $email,
        array $roles,
        string $password,
        int $defaultServing,
        DateTimeImmutable $createdAt,
    ): static {
        return new static(
            $id,
            $email,
            $roles,
            $password,
            $defaultServing,
            Status::ACTIVE,
            ['createdAt' => $createdAt],
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
            $datas['status'],
            $datas['statistics'],
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

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function setStatus(Status $status): User
    {
        $this->status = $status;
        return $this;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    public function createRecipe(): User
    {
        $this->statistics['recipes'] ??= 0;
        $this->statistics['recipes']++;
        return $this;
    }

    public function createIngredient(): User
    {
        $this->statistics['ingredient'] ??= 0;
        $this->statistics['ingredient']++;
        return $this;
    }

    public function completedShoppingList(ShoppingList $shoppingList): User
    {
        $this->statistics['shoppingLists'] ??= [];
        $this->statistics['shoppingLists'][$shoppingList->getId()] = [
            'createdAt' => $shoppingList->getCreatedAt(),
            'meal' => $shoppingList->getMeals()->count(),
            'ingredient' => $shoppingList->getIngredients()->count(),
            'rows' => $shoppingList->getRows()->count(),
        ];
        return $this;
    }

    public function getShoppingListStats(): array
    {
        return $this->statistics['shoppingLists'] ?? [];
    }

    public function getRecipeStats(): int
    {
        return $this->statistics['recipes'] ?? 0;
    }

    public function getIngredientStats(): int
    {
        return $this->statistics['ingredient'] ?? 0;
    }

    public function getCreatedAt(): string
    {
        return $this->statistics['createdAt']->format('Y-m-d');
    }

    public function setStatistics(array $statistics): User
    {
        $this->statistics = $statistics;
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

    public function highestRole(): string
    {
        if ($this->isAdmin()) {
            return 'ROLE_ADMIN';
        } else {
            return 'ROLE_USER';
        }
    }

    public function statusValue(): string
    {
        return $this->status->value;
    }

    public function update(string $defaultServing): void
    {
        $this->defaultServing = $defaultServing;
    }

    public function passwordMatch(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function isDisabled(): bool
    {
        return $this->status === Status::DISABLED;
    }
}

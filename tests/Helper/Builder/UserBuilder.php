<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus as Status;

class UserBuilder implements Builder
{
    /**
     * L'adresse du compte propriétaire du catalogue d'ingrédients dans les tests : celle de `INGREDIENT_CATALOG_OWNER_EMAIL` de `.env.test`
     * (jamais l'adresse du compte protégé de production, qui ne s'écrit qu'à un endroit du code).
     */
    public const string CATALOG_OWNER_EMAIL = 'catalog-owner@repas.test';

    private string $email;
    private array $roles;
    private string $password;
    private string $id;
    private int $defaultServing;
    private Status $status;
    private array $statistics;
    private ?DateTimeImmutable $lastLoginAt = null;

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->email ??= "{$this->id}@example.com";
        $this->roles ??= ['ROLE_USER'];
        $this->password ??= '$2y$13$0LFXi/NjQ/Ic36vx5MLGqu5kidPlsWf.xctG6xXNs1YYFiMLLLMym';// Password hashed : Test
        $this->defaultServing ??= 4;
        $this->status ??= Status::ACTIVE;
        $this->statistics ??= ['createdAt' => new DateTimeImmutable()];
    }

    public function build(): User
    {
        $this->initialize();
        return User::load([
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles,
            'password' => $this->password,
            'default_serving' => $this->defaultServing,
            'status' => $this->status,
            'statistics' => $this->statistics,
            'last_login_at' => $this->lastLoginAt,
        ]);
    }

    /**
     * Dernière connexion du MODÈLE construit (ce que `User::load()` recevrait de la base). `UserRepository::save()` ne l'écrit que
     * pour un compte NOUVEAU, jamais pour un compte existant : pour la changer en base, un test passe par
     * `UserRepository::recordLogin()`.
     */
    public function withLastLoginAt(?DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function withEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Le propriétaire du catalogue d'ingrédients (ADR 0028, amendement) tel que les tests le désignent : un administrateur dont l'adresse est
     * celle de `INGREDIENT_CATALOG_OWNER_EMAIL`.
     */
    public function isCatalogOwner(): static
    {
        return $this->withEmail(self::CATALOG_OWNER_EMAIL)->isAdmin();
    }

    public function isAdmin(): static
    {
        $this->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        return $this;
    }

    public function isDisabled(): static
    {
        $this->status = Status::DISABLED;
        return $this;
    }

    public function isPending(): static
    {
        $this->status = Status::PENDING;
        return $this;
    }

    public function withId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function withServing(int $serving): self
    {
        $this->defaultServing = $serving;
        return $this;
    }

    public function withPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function withCreatedAt(DateTimeImmutable $now): self
    {
        $this->statistics ??= [];
        $this->statistics['createdAt'] = $now;
        return $this;
    }

    public function withRecipeAndIngredientStats(int $recipe, int $ingredient): self
    {
        $this->statistics ??= [];
        $this->statistics['recipes'] = $recipe;
        $this->statistics['ingredients'] = $ingredient;
        return $this;
    }
}

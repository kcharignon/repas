<?php

namespace Repas\User\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\User\Domain\Exception\UserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Repas\User\Domain\Model\UserStatus as Status;

class User implements UserInterface, PasswordAuthenticatedUserInterface, PasswordUpgraderInterface, ModelInterface, AuthenticatedUser
{

    use ModelTrait;

    /** Adresse du compte protégé (voir `isProtected()`). */
    public const string PROTECTED_EMAIL = 'kantincharignon@gmail.com';

    private function __construct(
        private string $id,
        private string $email,
        private array  $roles,
        private string $password,
        private int    $defaultServing,
        private Status $status,
        private array  $statistics,
        private int    $credentialsVersion,
    ) {
    }

    /**
     * L'email est l'identifiant de connexion et il est unique : on le compare sans tenir compte de la casse ni des espaces
     * autour, donc on ne le stocke et ne le cherche qu'ainsi.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Compte créé hors inscription (fixtures, données de démonstration) : actif d'emblée, sans confirmation d'email.
     */
    public static function create(
        string $id,
        string $email,
        array $roles,
        string $password,
        int $defaultServing,
        DateTimeImmutable $createdAt,
    ): static {
        return self::instantiate($id, $email, $roles, $password, $defaultServing, $createdAt, Status::ACTIVE);
    }

    /**
     * Inscription d'un visiteur : le compte reste en attente (`PENDING`) tant qu'il n'a pas confirmé son adresse email
     * (`verifyEmail()`, ADR 0020).
     */
    public static function register(
        string $id,
        string $email,
        array $roles,
        string $password,
        int $defaultServing,
        DateTimeImmutable $createdAt,
    ): static {
        return self::instantiate($id, $email, $roles, $password, $defaultServing, $createdAt, Status::PENDING);
    }

    private static function instantiate(
        string $id,
        string $email,
        array $roles,
        string $password,
        int $defaultServing,
        DateTimeImmutable $createdAt,
        Status $status,
    ): static {
        return new static(
            $id,
            static::normalizeEmail($email),
            $roles,
            $password,
            $defaultServing,
            $status,
            ['createdAt' => $createdAt],
            0,
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
            $datas['credentials_version'] ?? 0,
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
        $this->email = static::normalizeEmail($email);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Remplace le hash sans que l'utilisateur ait changé de mot de passe (Symfony recalcule le hash à la connexion quand
     * l'algorithme ou son coût change) : les jetons émis restent valables.
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * Le mot de passe change réellement : la version des identifiants augmente, ce qui révoque les jetons déjà émis
     * (`JwtCredentialsVersionListener`, ADR 0008).
     */
    public function changePassword(string $password): void
    {
        $this->password = $password;
        $this->credentialsVersion++;
    }

    public function getCredentialsVersion(): int
    {
        return $this->credentialsVersion;
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

    public function createRecipes(int $number): User
    {
        $this->statistics['recipes'] ??= 0;
        $this->statistics['recipes'] += $number;
        return $this;
    }

    public function createIngredient(): User
    {
        $this->statistics['ingredients'] ??= 0;
        $this->statistics['ingredients']++;
        return $this;
    }

    public function createIngredients(int $number): User
    {
        $this->statistics['ingredients'] ??= 0;
        $this->statistics['ingredients'] += $number;
        return $this;
    }

    public function completedShoppingList(string $shoppingListId, DateTimeImmutable $createdAt, int $meals, int $ingredients, int $rows): User
    {
        $this->statistics['shoppingLists'] ??= [];
        $this->statistics['shoppingLists'][$shoppingListId] = [
            'createdAt' => $createdAt,
            'meals' => $meals,
            'ingredients' => $ingredients,
            'rows' => $rows,
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
        return $this->statistics['ingredients'] ?? 0;
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

    public function update(int $defaultServing): void
    {
        $this->defaultServing = $defaultServing;
    }

    public function passwordMatch(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * L'administrateur fixe le statut du compte, quel que soit l'ancien (ADR 0021). Seule règle : le compte protégé est
     * intouchable, il ne peut qu'être actif.
     *
     * @throws UserException le compte est protégé et le statut demandé le rendrait inutilisable
     */
    public function changeStatus(Status $status): void
    {
        if ($status !== Status::ACTIVE && $this->isProtected()) {
            throw UserException::protectedAccount();
        }

        $this->status = $status;
    }

    /**
     * Compte intouchable : personne ne peut le désactiver, le remettre en attente ni le supprimer, pas même un administrateur.
     * C'est celui de son propriétaire, qui perdrait sinon l'administration sans reprise possible (ADR 0021).
     */
    public function isProtected(): bool
    {
        return $this->email === static::normalizeEmail(self::PROTECTED_EMAIL);
    }

    /**
     * @throws UserException le compte est protégé
     */
    public function assertCanBeDeleted(): void
    {
        if ($this->isProtected()) {
            throw UserException::protectedAccount();
        }
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    public function isDisabled(): bool
    {
        return $this->status === Status::DISABLED;
    }

    public function isPending(): bool
    {
        return $this->status === Status::PENDING;
    }

    /**
     * L'adresse est confirmée : un compte en attente devient actif. Un compte désactivé le reste (confirmer une adresse ne
     * lève pas une désactivation) et un compte déjà actif n'est pas modifié.
     */
    public function verifyEmail(): void
    {
        if ($this->status === Status::PENDING) {
            $this->status = Status::ACTIVE;
        }
    }
}

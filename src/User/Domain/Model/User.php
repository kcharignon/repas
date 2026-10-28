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

    /**
     * Mot de passe d'un compte qui n'en a pas encore : jamais un hachage valide, donc `password_verify` le refuse pour toute saisie. C'est
     * celui d'un compte inscrit avec la seule adresse (le mot de passe se choisit après le clic sur le lien reçu par email, ADR 0020
     * amendé) ; la connexion avec Google en pose un aussi (ADR 0024). Aucune colonne de plus : la valeur suffit (`hasPassword()`).
     */
    public const string NO_PASSWORD = '!';

    /** Nombre de personnes par défaut d'un compte : de 1 à 100, comme celui d'une recette et d'un repas. */
    public const int MIN_SERVING = 1;
    public const int MAX_SERVING = 100;
    /** Nombre de personnes d'un compte inscrit sans l'avoir dit (modifiable ensuite dans « Moi »). */
    public const int DEFAULT_SERVING = 4;

    /**
     * Écart minimal, en secondes, entre deux enregistrements de la dernière connexion d'un même compte (voir
     * `shouldRecordLogin()`) : une session ouverte se renouvelle toutes les heures, elle n'a pas besoin d'une écriture à chaque fois.
     */
    public const int LOGIN_RECORD_INTERVAL_SECONDS = 600;

    private function __construct(
        private string $id,
        private string $email,
        private array  $roles,
        private string $password,
        private int    $defaultServing,
        private Status $status,
        private array  $statistics,
        private int    $credentialsVersion,
        private ?DateTimeImmutable $lastLoginAt = null,
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
     * Inscription d'un visiteur avec sa seule adresse : le compte reste en attente (`PENDING`) et SANS mot de passe (`NO_PASSWORD`)
     * jusqu'au clic sur le lien reçu par email, qui lui fait choisir son mot de passe et active le compte (ADR 0020 amendé, ADR 0026).
     * Le compte n'a pas de mot de passe à ce stade : il le choisit en ouvrant le lien reçu par email.
     */
    public static function register(
        string $id,
        string $email,
        array $roles,
        int $defaultServing,
        DateTimeImmutable $createdAt,
    ): static {
        return self::instantiate($id, $email, $roles, self::NO_PASSWORD, $defaultServing, $createdAt, Status::PENDING);
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
        self::assertServing($defaultServing);

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
            $datas['last_login_at'] ?? null,
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

    /**
     * Le compte a-t-il un mot de passe utilisable ? Faux pour un compte inscrit qui n'a pas encore choisi le sien (`NO_PASSWORD`).
     */
    public function hasPassword(): bool
    {
        return $this->password !== self::NO_PASSWORD;
    }

    /**
     * Retire le mot de passe du compte (`NO_PASSWORD`) et révoque les jetons déjà émis. Sans effet s'il n'en a pas. Sert
     * aux comptes encore en attente qui ont un ancien mot de passe (inscription de l'ancien formulaire) :
     * le compte en choisit un nouveau avec le lien reçu par email (ADR 0020 amendé).
     */
    public function lockPassword(): void
    {
        if ($this->hasPassword()) {
            $this->password = self::NO_PASSWORD;
            $this->credentialsVersion++;
        }
    }

    public function getCredentialsVersion(): int
    {
        return $this->credentialsVersion;
    }

    /**
     * Date de la dernière authentification réussie du compte, telle qu'elle était à la lecture (`null` : jamais vue depuis que
     * le suivi existe). C'est une copie : la valeur de la base peut avoir avancé depuis (`UserRepository::recordLogin()`).
     */
    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * Faut-il enregistrer une authentification réussie à l'instant `$now` ? Oui pour un compte actif dont la dernière connexion
     * est inconnue ou date d'au moins `LOGIN_RECORD_INTERVAL_SECONDS`. Se juge sur la valeur déjà chargée, sans requête : c'est ce
     * qui rend la plupart des connexions et des renouvellements gratuits. Un compte qui n'est pas actif ne s'authentifie pas
     * (ADR 0021) : s'il arrive ici, c'est une course avec un administrateur, et on n'écrit rien.
     */
    public function shouldRecordLogin(DateTimeImmutable $now): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->lastLoginAt === null
            || $now->getTimestamp() - $this->lastLoginAt->getTimestamp() >= self::LOGIN_RECORD_INTERVAL_SECONDS;
    }

    /**
     * Met à jour la copie en mémoire après un enregistrement de la connexion ; la base est écrite par
     * `UserRepository::recordLogin()`. Ne recule jamais : une date plus ancienne que celle connue est ignorée.
     */
    public function recordLogin(DateTimeImmutable $at): void
    {
        if ($this->lastLoginAt === null || $at > $this->lastLoginAt) {
            $this->lastLoginAt = $at;
        }
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getDefaultServing(): int
    {
        return $this->defaultServing;
    }

    /**
     * @throws UserException le nombre de personnes est hors de 1 à 100
     */
    public function setDefaultServing(int $defaultServing): User
    {
        self::assertServing($defaultServing);
        $this->defaultServing = $defaultServing;
        return $this;
    }

    private static function assertServing(int $serving): void
    {
        if ($serving < self::MIN_SERVING || $serving > self::MAX_SERVING) {
            throw UserException::invalidServing();
        }
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

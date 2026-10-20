<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

/**
 * Un compte dans le rapport de l'amorçage. `userId` sert à lancer la commande de ce compte et n'est JAMAIS affiché : le rapport montre `rank`
 * et `reference` (les 8 premiers caractères du SHA-256 de l'identifiant, de quoi désigner le compte dans `--user` sans le dévoiler). Aucun
 * e-mail, aucun nom d'ingrédient personnel.
 *
 * Les compteurs de détail ne sont remplis que pour un compte retenu (`selected`) ; sinon ils valent 0.
 */
final readonly class SeedingUserReportDTO
{
    /** Exclusions : le propriétaire du catalogue (ses ingrédients sont les siens, il n'est jamais amorcé). */
    public const string OWNER = 'OWNER';
    /** Exclusions : compte en attente ou désactivé (il sera servi à son activation) ; `--user` peut le viser quand même. */
    public const string NOT_ACTIVE = 'NOT_ACTIVE';
    /** Exclusions : le compte est déjà amorcé, on n'y touche pas (sauf `--complete` sur ce compte). */
    public const string ALREADY_SEEDED = 'ALREADY_SEEDED';
    /** Exclusions : écarté par `--except`. */
    public const string EXCEPTED = 'EXCEPTED';
    /** Exclusions : hors de la sélection de `--user`. */
    public const string NOT_SELECTED = 'NOT_SELECTED';

    /**
     * @param string $status statut du compte (`ACTIVE`, `PENDING`, `DISABLED`)
     * @param bool $seeded le compte a au moins un alias d'adoption
     * @param int $ingredients ingrédients à lui (créateur = lui)
     * @param ?string $exclusion une des constantes ci-dessus quand le compte n'est pas retenu, sinon null
     * @param int $toSeed ingrédients qui seraient copiés (« de base » plus ceux que ses lignes référencent)
     * @param int $toSeedBase dont « de base »
     * @param int $toSeedReferenced dont copiés seulement parce que ses lignes les référencent
     * @param int $reusable déjà copiés par le compte (copie conforme sans alias), désignés par l'alias sans nouvelle copie
     * @param int $slugCollisions copies dont le slug naturel est déjà pris par un ingrédient du compte (elles prendront `-2`, `-3`…)
     * @param list<string> $blockingAnomalies interdisent d'écrire ; jamais d'identifiant ni de nom d'ingrédient personnel
     */
    public function __construct(
        public int $rank,
        public string $reference,
        public string $userId,
        public string $status,
        public bool $owner,
        public bool $seeded,
        public int $ingredients,
        public int $recipes,
        public bool $selected,
        public ?string $exclusion,
        public int $toSeed = 0,
        public int $toSeedBase = 0,
        public int $toSeedReferenced = 0,
        public int $reusable = 0,
        public int $slugCollisions = 0,
        public int $conversionsToCopy = 0,
        public int $recipeRowsToRepoint = 0,
        public int $recipesToRepoint = 0,
        public int $shoppingListRowsToRepoint = 0,
        public int $shoppingListIngredientsToRepoint = 0,
        public int $shoppingListsToRepoint = 0,
        public int $sentShoppingListsToRepoint = 0,
        public int $driveChoicesToRepoint = 0,
        public int $driveProductsOfChoicesToRepoint = 0,
        public int $driveChoices = 0,
        public int $driveProducts = 0,
        public string $fingerprint = '',
        public array $blockingAnomalies = [],
    ) {
    }

    /**
     * Les lignes des recettes et des listes que le repointage modifierait.
     */
    public function linesToRepoint(): int
    {
        return $this->recipeRowsToRepoint + $this->shoppingListRowsToRepoint + $this->shoppingListIngredientsToRepoint;
    }

    /**
     * Rien à copier, rien à repointer : « 0 à copier, 0 à repointer ».
     */
    public function isNothingToDo(): bool
    {
        return $this->toSeed === 0 && $this->linesToRepoint() === 0 && $this->driveChoicesToRepoint === 0;
    }
}

<?php

namespace Repas\Repas\Application\Query\GetAdoptionReport;

/**
 * Un compte dans le rapport de la migration d'adoption. `userId` sert à lancer la commande de ce compte et n'est JAMAIS affiché : le
 * rapport montre `rank` et `reference` (les 8 premiers caractères du SHA-256 de l'identifiant, de quoi désigner le compte dans
 * `--user` sans le dévoiler). Aucun e-mail, aucun nom d'ingrédient personnel.
 *
 * Les compteurs de détail ne sont remplis que pour un compte concerné ET retenu (`selected`) ; sinon ils valent 0.
 */
final readonly class AdoptionUserReportDTO
{
    /** Exclusions : le compte est administrateur (il garde les communs, il en est le propriétaire futur, ADR 0028 décision a). */
    public const string ADMINISTRATOR = 'ADMINISTRATOR';
    /** Exclusions : ni ingrédient personnel ni recette, rien à migrer ; il continue d'utiliser les communs partagés. */
    public const string NOTHING_OWNED = 'NOTHING_OWNED';
    /** Exclusions : écarté par `--except`. */
    public const string EXCEPTED = 'EXCEPTED';
    /** Exclusions : hors de la sélection de `--user`. */
    public const string NOT_SELECTED = 'NOT_SELECTED';

    /**
     * @param string $status statut du compte (`ACTIVE`, `PENDING`, `DISABLED`)
     * @param ?string $exclusion une des constantes ci-dessus quand le compte n'est pas retenu, sinon null
     * @param int $slugCollisions copies dont le slug naturel est déjà pris par un ingrédient du compte (elles prendront `-2`, `-3`…)
     * @param list<string> $collidingCommons les noms (de communs, donc publics) des communs concernés par ces collisions
     * @param list<string> $blockingAnomalies interdisent d'écrire ; jamais d'identifiant ni de nom d'ingrédient personnel
     * @param int $invalidAliases alias d'adoption dont la copie manque, n'est pas au compte ou ne vient pas du commun de l'alias
     */
    public function __construct(
        public int $rank,
        public string $reference,
        public string $userId,
        public string $status,
        public bool $administrator,
        public int $personalIngredients,
        public int $adoptedCopies,
        public int $recipes,
        public int $copiedRecipes,
        public bool $concerned,
        public bool $selected,
        public ?string $exclusion,
        public int $toAdopt = 0,
        public int $alreadyAdopted = 0,
        public int $slugCollisions = 0,
        public array $collidingCommons = [],
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
        public int $invalidAliases = 0,
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
     * Rien à adopter, rien à repointer, aucun alias invalide : « 0 à adopter, 0 à repointer ».
     */
    public function isNothingToDo(): bool
    {
        return $this->toAdopt === 0 && $this->linesToRepoint() === 0 && $this->driveChoicesToRepoint === 0 && $this->invalidAliases === 0;
    }
}

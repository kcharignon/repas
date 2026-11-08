<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class RecipeException extends DomainException
{
    // Messages des bornes d'une recette : le front les distingue, ils ne se renomment pas
    public const string QUANTITY_INVALID = 'RECIPE_QUANTITY_INVALID';
    public const string SERVING_INVALID = 'RECIPE_SERVING_INVALID';
    public const string ROWS_LIMIT_REACHED = 'RECIPE_ROWS_LIMIT_REACHED';
    public const string LIMIT_REACHED = 'RECIPE_LIMIT_REACHED';
    public const string SECRET_FORBIDDEN = 'RECIPE_SECRET_FORBIDDEN';
    public const string FAVORITE_FORBIDDEN = 'RECIPE_FAVORITE_FORBIDDEN';
    public const string TYPE_TRANSFER_TO_ITSELF = 'RECIPE_TYPE_TRANSFER_TO_ITSELF';
    public const string MARMITON_FROZEN = 'RECIPE_MARMITON_FROZEN';
    // Recettes utilisées dans d'autres recettes (ADR 0040 décision 29) : le front les distingue, ils ne se renomment pas
    public const string COMPONENT_SELF = 'RECIPE_COMPONENT_SELF';
    public const string COMPONENT_DUPLICATE = 'RECIPE_COMPONENT_DUPLICATE';
    public const string COMPONENT_MULTIPLIER_INVALID = 'RECIPE_COMPONENT_MULTIPLIER_INVALID';
    public const string COMPONENT_LIMIT_REACHED = 'RECIPE_COMPONENT_LIMIT_REACHED';
    public const string COMPONENT_CHAIN = 'RECIPE_COMPONENT_CHAIN';
    public const string COMPONENT_OWNERLESS = 'RECIPE_COMPONENT_OWNERLESS';
    public const string EMPTY = 'RECIPE_EMPTY';
    public const string COMPONENT_SECRET = 'RECIPE_COMPONENT_SECRET';
    public const string SECRET_USED_BY_PUBLIC = 'RECIPE_SECRET_USED_BY_PUBLIC';
    public const string USED_AS_COMPONENT = 'RECIPE_USED_AS_COMPONENT';

    public static function notFound(string $id): static
    {
        return new static(sprintf('Recipe id %s not found', $id), 404);
    }

    public static function typeNotFound(string $type): static
    {
        return new static(sprintf("Recipe type '%s' not found", $type), 404);
    }

    public static function rowSubModelNotFound(string $recipe): static
    {
        return new static(sprintf("Can't load recipe '%s'", $recipe), 404);
    }

    public static function cannotRemoveExistInShoppingList(string $recipeId): static
    {
        return new static(sprintf("Cannot remove recipe '%s', present in shopping list", $recipeId), 409);
    }

    /**
     * Le client a lu une autre version que celle d'aujourd'hui (`If-Match`).
     */
    public static function outdated(string $id): static
    {
        return new static(sprintf("Recipe '%s' was modified since it was read", $id), 412);
    }

    /**
     * Quelqu'un d'autre a enregistré la recette entre la lecture et l'écriture de cette requête.
     */
    public static function concurrentModification(string $id): static
    {
        return new static(sprintf("Recipe '%s' was modified by someone else, try again", $id), 409);
    }

    public static function typeAlreadyExists(string $slug): self
    {
        return new self(sprintf("Recipe type '%s' already exists", $slug), 409);
    }

    /**
     * La quantité d'une ligne est un nombre fini, strictement positif, d'un million au plus (`QuantityBounds`). Règle métier, `422`.
     */
    public static function invalidQuantity(): self
    {
        return new self(self::QUANTITY_INVALID, 422);
    }

    /**
     * Le nombre de personnes d'une recette va de 1 à 100. Règle métier, `422`.
     */
    public static function invalidServing(): self
    {
        return new self(self::SERVING_INVALID, 422);
    }

    /**
     * Une recette n'a pas plus de lignes que `Recipe::MAX_ROWS`. Règle métier, `422`.
     */
    public static function tooManyRows(): self
    {
        return new self(self::ROWS_LIMIT_REACHED, 422);
    }

    /**
     * L'auteur a déjà toutes les recettes que l'application lui permet d'écrire (`Recipe::MAX_PER_AUTHOR`), ou d'avoir en comptant ses
     * copies (`Recipe::MAX_PER_AUTHOR_WITH_COPIES`). Règle métier, `422`.
     */
    public static function limitReached(): self
    {
        return new self(self::LIMIT_REACHED, 422);
    }

    /**
     * Seul l'auteur d'une recette la rend secrète ou de nouveau visible (ADR 0033) : un autre utilisateur, un administrateur compris, qui
     * envoie une autre valeur que l'actuelle reçoit un refus d'autorisation, `403`, jamais ignoré en silence.
     */
    public static function secretForbidden(): self
    {
        return new self(self::SECRET_FORBIDDEN, 403);
    }

    /**
     * Seul l'auteur d'une recette la met en favoris ou l'en retire : un autre utilisateur, un administrateur compris, qui envoie une autre
     * valeur que l'actuelle reçoit un refus d'autorisation, `403`, jamais ignoré en silence.
     */
    public static function favoriteForbidden(): self
    {
        return new self(self::FAVORITE_FORBIDDEN, 403);
    }

    /**
     * La recette Marmiton neutre n'appartient à personne et ne se modifie pas, administrateur compris : on la copie (ADR 0037 décision 5).
     * Refus d'autorisation, `403`.
     */
    public static function frozen(): self
    {
        return new self(self::MARMITON_FROZEN, 403);
    }

    /**
     * Les recettes d'un type supprimé passent dans un autre type : le type de transfert ne peut pas être le type supprimé lui-même. `422`.
     */
    public static function typeTransferToItself(): self
    {
        return new self(self::TYPE_TRANSFER_TO_ITSELF, 422);
    }

    /**
     * Une recette ne se compose pas d'elle-même (ADR 0040 décision 6). Règle métier, `422`.
     */
    public static function componentSelf(): self
    {
        return new self(self::COMPONENT_SELF, 422);
    }

    /**
     * Une même recette n'est utilisée qu'une fois par une recette : l'ordre ne change rien, on règle le multiplicateur (ADR 0040 décision 6).
     * Règle métier, `422`.
     */
    public static function componentDuplicate(): self
    {
        return new self(self::COMPONENT_DUPLICATE, 422);
    }

    /**
     * Le multiplicateur d'une recette utilisée est un nombre fini de 0,01 à 100 (`RecipeComponent::MIN_MULTIPLIER`, `MAX_MULTIPLIER`, ADR 0040
     * décision 5). Règle métier, `422`.
     */
    public static function componentMultiplierInvalid(): self
    {
        return new self(self::COMPONENT_MULTIPLIER_INVALID, 422);
    }

    /**
     * Une recette n'en utilise pas plus de `Recipe::MAX_COMPONENTS` (ADR 0040 décision 6). Règle métier, `422`.
     */
    public static function componentLimitReached(): self
    {
        return new self(self::COMPONENT_LIMIT_REACHED, 422);
    }

    /**
     * Profondeur 1, dans les deux sens (ADR 0040 décision 6) : une recette qui a des composants ne peut pas être utilisée par une autre, et une
     * recette utilisée ne peut pas avoir de composants. Règle métier, `422`.
     */
    public static function componentChain(): self
    {
        return new self(self::COMPONENT_CHAIN, 422);
    }

    /**
     * Une recette sans propriétaire (la neutre d'une recette Marmiton) n'est jamais composée (ADR 0040 décision 7) : on la copie, et la copie le
     * peut. Règle métier, `422`.
     */
    public static function componentOwnerless(): self
    {
        return new self(self::COMPONENT_OWNERLESS, 422);
    }

    /**
     * Une recette contient quelque chose : au moins une ligne ou une recette utilisée (ADR 0040 décision 9). Règle métier, `422`.
     */
    public static function empty(): self
    {
        return new self(self::EMPTY, 422);
    }

    /**
     * Une recette ordinaire n'utilise que des recettes ordinaires (ADR 0040 décision 8) : sinon la lire ou la copier révélerait le contenu d'une
     * recette secrète. Refusé à l'écriture d'un composant secret et quand on rend publique une recette qui en a un. Conflit d'état, `409`.
     */
    public static function componentSecret(): self
    {
        return new self(self::COMPONENT_SECRET, 409);
    }

    /**
     * Une recette qu'une recette ordinaire utilise ne devient pas secrète (ADR 0040 décision 8). Conflit d'état, `409`.
     */
    public static function secretUsedByPublic(): self
    {
        return new self(self::SECRET_USED_BY_PUBLIC, 409);
    }

    /**
     * Une recette qu'une autre recette utilise ne se supprime pas (ADR 0040 décision 31). Conflit d'état, `409`.
     */
    public static function usedAsComponent(): self
    {
        return new self(self::USED_AS_COMPONENT, 409);
    }
}

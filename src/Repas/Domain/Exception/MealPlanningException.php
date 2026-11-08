<?php

namespace Repas\Repas\Domain\Exception;

use Repas\Shared\Domain\Exception\DomainException;

/**
 * Un échec CONNU de la planification d'un repas dans la liste de courses (ADR 0029 décision 13) : l'appelant en tire une phrase claire plutôt
 * qu'une panne anonyme (2026-10-26 : « blanquette de veaux » échouait sans que l'utilisateur sache pourquoi). Levée par les cas d'usage de
 * `Repas` ; la commande entière est annulée (le bus est transactionnel : rien n'est écrit). Le nom de la recette et celui de l'ingrédient sont ceux
 * de l'utilisateur, jamais une donnée d'un autre ; ils servent à dire l'échec à leur propriétaire, jamais au journal.
 */
final class MealPlanningException extends DomainException
{
    /** Un ingrédient de la recette n'a aucune conversion vers son unité d'achat : les quantités de la liste ne se calculent pas. */
    public const string MISSING_CONVERSION = 'MISSING_CONVERSION';

    /** La recette n'existe plus, ou n'est pas à l'utilisateur. */
    public const string RECIPE_GONE = 'RECIPE_GONE';

    private function __construct(
        public readonly string $reason,
        public readonly ?string $recipeName,
        public readonly ?string $ingredientName = null,
        public readonly ?string $componentName = null,
    ) {
        parent::__construct($reason, 422);
    }

    /**
     * @param ?string $ingredientName l'ingrédient (de l'utilisateur) dont l'unité ne se convertit pas ; `null` quand on ne le sait pas
     * @param ?string $componentName  la recette UTILISÉE par la recette (ADR 0040 décision 16) d'où vient la ligne qui ne se convertit pas, pour dire
     *                                laquelle ouvrir ; `null` quand la ligne est de la recette elle-même
     */
    public static function missingConversion(string $recipeName, ?string $ingredientName = null, ?string $componentName = null): self
    {
        return new self(self::MISSING_CONVERSION, $recipeName, $ingredientName, $componentName);
    }

    public static function recipeGone(): self
    {
        return new self(self::RECIPE_GONE, null);
    }
}

<?php

namespace Repas\Voice\Domain\Exception;

use Repas\Shared\Domain\Exception\DomainException;

/**
 * Un échec CONNU de la commande vocale d'un repas (ADR 0029 décision 13) : le webhook en tire une phrase claire plutôt que « Je n'ai pas pu faire ça »
 * (2026-10-26 : « blanquette de veaux » échouait sans que l'utilisateur sache pourquoi). Levée par la couche anticorruption (`MealPlanCommandAdapter`)
 * qui traduit l'échec de `Repas` ; la commande entière est annulée (le bus est transactionnel : rien n'est écrit). Le nom de la recette est celui de
 * l'utilisateur, jamais une donnée d'un autre ; il sert à la phrase, jamais au journal (le webhook ne journalise ni le message ni le nom).
 */
final class SpokenMealFailure extends DomainException
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
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 422, $previous);
    }

    /**
     * @param ?string $ingredientName l'ingrédient (de l'utilisateur) dont l'unité ne se convertit pas : Alexa le nomme, comme l'application ; `null` quand
     *                                on ne le sait pas (la phrase retombe sur le nom de la recette). Comme le nom de la recette : dit à son propriétaire, jamais journalisé.
     * @param ?string $componentName  la recette UTILISÉE par la recette (ADR 0040 décision 16) d'où vient la ligne qui ne se convertit pas : Alexa la nomme
     *                                (« … pour farine, dans pâte sablée. »), car c'est elle qu'il faut ouvrir ; `null` quand la ligne est de la recette elle-même.
     *                                Dit à son propriétaire, jamais journalisé.
     */
    public static function missingConversion(string $recipeName, ?string $ingredientName = null, ?string $componentName = null, ?\Throwable $previous = null): self
    {
        return new self(self::MISSING_CONVERSION, $recipeName, $ingredientName, $componentName, $previous);
    }

    public static function recipeGone(?\Throwable $previous = null): self
    {
        return new self(self::RECIPE_GONE, null, previous: $previous);
    }
}

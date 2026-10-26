<?php

namespace Repas\Shared\Domain\Exception;


/**
 * Un échec CONNU de la commande vocale d'un repas (ADR 0029 décision 13) : le webhook en tire une phrase claire plutôt que « Je n'ai pas pu faire ça »
 * (2026-10-26 : « blanquette de veaux » échouait sans que l'utilisateur sache pourquoi). Levée par les cas d'usage de `Repas` ; la commande entière est
 * annulée (le bus est transactionnel : rien n'est écrit). Le nom de la recette est celui de l'utilisateur, jamais une donnée d'un autre ; il sert à la
 * phrase, jamais au journal (le webhook ne journalise ni le message ni le nom).
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
    ) {
        parent::__construct($reason, 422);
    }

    public static function missingConversion(string $recipeName): self
    {
        return new self(self::MISSING_CONVERSION, $recipeName);
    }

    public static function recipeGone(): self
    {
        return new self(self::RECIPE_GONE, null);
    }
}

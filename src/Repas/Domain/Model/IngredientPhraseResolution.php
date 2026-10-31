<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que le classement d'une phrase prononcée rend (`IngredientPhraseResolver`, ADR 0029 décisions 3 et 11) : SOIT l'ingrédient retenu, SOIT
 * — le produit est inconnu — au plus trois propositions (liste vide : rien d'assez proche), SOIT — plusieurs ingrédients de l'utilisateur se
 * valent pour cette phrase (« tomate » en fruit et légume et « tomate » en surgelé, habitudes qui ne tranchent pas) — les ex æquo à départager
 * (deux ou trois, chacun avec son rayon). Un seul des trois à la fois.
 */
final readonly class IngredientPhraseResolution
{
    /**
     * @param list<RankedIngredient> $suggestions
     * @param list<RankedIngredient> $ties les ex æquo à départager (jamais en même temps qu'un ingrédient ou des propositions)
     */
    private function __construct(
        public ?RankedIngredient $ingredient,
        public array $suggestions,
        public array $ties = [],
    ) {
    }

    public static function found(RankedIngredient $ingredient): self
    {
        return new self($ingredient, []);
    }

    /**
     * @param list<RankedIngredient> $ties au moins deux ingrédients de l'utilisateur, tous discernables par leur nom et leur rayon à la fois
     */
    public static function tied(array $ties): self
    {
        return new self(null, [], $ties);
    }

    /**
     * @param list<RankedIngredient> $suggestions
     */
    public static function unknown(array $suggestions = []): self
    {
        return new self(null, $suggestions);
    }
}

<?php

namespace Repas\Drive\Application\OpenHostService;

/**
 * Ce que la fusion de deux ingrédients ferait des choix de produits d'un utilisateur : des nombres, aucun nom, aucune enseigne.
 */
final readonly class IngredientChoiceMergeImpactDTO
{
    /**
     * @param int $choicesMoved les choix de l'absorbé qui passent sur le gardé (aucun choix du gardé dans le même magasin)
     * @param int $choicesDropped les choix de l'absorbé supprimés, parce que le gardé a le sien dans le même magasin
     * @param int $productsDropped les lignes de produits mémorisés supprimées avec ces choix
     */
    public function __construct(
        public int $choicesMoved = 0,
        public int $choicesDropped = 0,
        public int $productsDropped = 0,
    ) {
    }
}

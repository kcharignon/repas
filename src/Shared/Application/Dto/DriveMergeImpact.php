<?php

namespace Repas\Shared\Application\Dto;


/**
 * Ce que la fusion de deux ingrédients d'un utilisateur fait (ou ferait) de ses choix du drive : pour chaque magasin (enseigne et
 * magasin), le choix de l'ingrédient gardé gagne ; là où seul l'ingrédient absorbé en a un, il passe sur le gardé avec ses produits ;
 * là où les deux en ont un, celui de l'absorbé est supprimé avec ses produits. Rien n'est jamais mélangé, rien n'écrase le choix du gardé
 * (`docs/ingredients/fusion.md` § 2 règle 9).
 *
 * Des nombres : aucun nom, aucune enseigne (le chantier n'en nomme aucune).
 */
final readonly class DriveMergeImpact
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

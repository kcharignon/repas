<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Le verrou des recettes qui en utilisent d'autres, PAR AUTEUR (ADR 0040 décision 27) : deux écritures qui se croisent se suivent au lieu de casser
 * la profondeur 1 ou la règle « une recette ordinaire n'utilise que des recettes ordinaires ». Donner des composants à une recette pendant qu'une autre
 * en fait une recette utilisée, rendre secrète une recette pendant qu'on l'utilise : chacune juge ce que l'autre vient d'écrire.
 *
 * L'auteur est la bonne granularité : toutes les recettes en cause (la composée, les utilisées, celles qui l'utilisent) sont les siennes. Deux auteurs
 * ne s'attendent jamais. Pris par toute écriture qui pose des composants non vides et par tout changement de `secret`, JAMAIS par une écriture qui ne
 * touche pas aux composants, ni par la suppression d'une recette (un orphelin est toléré, décision 21).
 */
interface RecipeComponentLock
{
    /**
     * Prend le verrou de cet auteur pour la durée de la transaction en cours (le bus des commandes est transactionnel : il est relâché au `COMMIT` ou au
     * `ROLLBACK`, jamais à la main). Bloque tant qu'une autre transaction le tient. À prendre AVANT de lire ce qu'il protège : une lecture faite plus
     * tôt pourrait ne pas voir l'écriture de l'autre transaction.
     */
    public function acquire(string $authorId): void;
}

<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;

interface IngredientToFixLoader
{
    /** Le nombre d'ingrédients rendus au plus ; `totalItems` dit le vrai nombre. */
    public const int LIMIT = 200;

    /**
     * Les ingrédients de cet utilisateur (les siens, jamais ceux d'un autre) dont l'unité des recettes ne se convertit pas en l'unité d'achat :
     * aucune conversion propre ni universelle, aucune suite de conversions ne les relie (le même `ConversionGraph` que la planification et que
     * `convertible` de la lecture d'une recette). Un ingrédient dont les deux unités sont la même n'est jamais à régler. Triés par nom puis par
     * identifiant (`LIMIT` au plus), le même résultat à chaque appel ; un nombre FIXE de lectures, quel que soit le nombre d'ingrédients.
     */
    public function toFix(string $userId): IngredientsToFixDTO;
}

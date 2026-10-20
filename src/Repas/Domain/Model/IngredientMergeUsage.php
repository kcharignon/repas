<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que la fusion de l'ingrédient absorbé `B` dans l'ingrédient gardé `A` touchera chez l'utilisateur, lu sans rien écrire
 * (`docs/ingredients/fusion.md` §§ 3 et 5). Des nombres, des slugs d'unités et des booléens (ADR 0013).
 */
final readonly class IngredientMergeUsage
{
    /**
     * @param int $recipeCount les recettes de l'utilisateur qui utilisent `B`
     * @param int $listCount les listes de courses de l'utilisateur qui utilisent `B` (une ligne ou un ingrédient ajouté à la main)
     * @param list<string> $recipeUnitSlugs les unités des lignes de ses recettes qui utilisent `B`, sans doublon : chacune doit se convertir
     *        dans l'unité d'achat de `A`
     * @param list<MergeUnitMove> $listMoves les changements d'unité des quantités de ses listes, sans doublon (identités comprises)
     * @param int $sentListCount ses listes envoyées au drive qui utilisent `A` ou `B`
     * @param bool $usedByAnotherUser une ligne d'un AUTRE utilisateur (recette ou liste) référence `B`
     */
    public function __construct(
        public int $recipeCount = 0,
        public int $listCount = 0,
        public array $recipeUnitSlugs = [],
        public array $listMoves = [],
        public int $sentListCount = 0,
        public bool $usedByAnotherUser = false,
    ) {
    }
}

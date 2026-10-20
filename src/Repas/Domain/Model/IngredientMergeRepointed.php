<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que le repointage en masse d'une fusion a modifié : les recettes et les listes dont la version a monté d'un cran (ADR 0016).
 */
final readonly class IngredientMergeRepointed
{
    public function __construct(
        public int $recipes = 0,
        public int $shoppingLists = 0,
    ) {
    }
}

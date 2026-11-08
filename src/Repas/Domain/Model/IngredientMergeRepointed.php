<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que le repointage en masse d'une fusion a modifié : les recettes et les listes dont la version a monté d'un cran (ADR 0016), les parties de
 * l'absorbé devenues celles du gardé et le lien dont le gardé a hérité (ADR 0038 décision 25).
 */
final readonly class IngredientMergeRepointed
{
    public function __construct(
        public int $recipes = 0,
        public int $shoppingLists = 0,
        public int $parts = 0,
        public bool $linkInherited = false,
    ) {
    }
}

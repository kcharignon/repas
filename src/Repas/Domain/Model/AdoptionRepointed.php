<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce qu'un repointage en masse (migration d'adoption ING-003 ou son retour arrière) a modifié pour un utilisateur : des lignes et des
 * agrégats dont la version a monté d'un cran. Des nombres seulement.
 */
final readonly class AdoptionRepointed
{
    public function __construct(
        public int $recipeRows = 0,
        public int $recipes = 0,
        public int $shoppingListRows = 0,
        public int $shoppingListIngredients = 0,
        public int $shoppingLists = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->recipeRows + $this->shoppingListRows + $this->shoppingListIngredients === 0;
    }
}

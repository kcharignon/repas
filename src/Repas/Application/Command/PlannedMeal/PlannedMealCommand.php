<?php

namespace Repas\Repas\Application\Command\PlannedMeal;


readonly class PlannedMealCommand
{

    /**
     * @param ?string $newMainListId l'identifiant d'une liste principale à CRÉER quand un produit y va et qu'elle n'est pas en cours (la commande vocale,
     *                               LST-008) ; `null` : l'absence de liste principale reste une erreur (`activeShoppingListNotFound`), comme pour
     *                               `PUT /shopping-lists/active/recipes/{recipeId}`
     */
    public function __construct(
        public string $ownerId,
        public string $recipeId,
        public int $defaultServing,
        public ?int $expectedVersion = null,
        public ?string $newMainListId = null,
    ) {
    }
}

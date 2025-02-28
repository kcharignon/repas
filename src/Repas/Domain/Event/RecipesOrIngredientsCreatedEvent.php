<?php

namespace Repas\Repas\Domain\Event;


use Repas\Shared\Domain\Tool\Tab;

readonly class RecipesOrIngredientsCreatedEvent
{

    /**
     * @param Tab<string> $ingredientSlugs
     * @param Tab<string> $recipeIds
     */
    public function __construct(
        public string $userId,
        public Tab $ingredientSlugs,
        public Tab $recipeIds,
    ) {
    }
}

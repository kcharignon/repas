<?php

namespace Repas\Repas\Domain\Event;


use Repas\Shared\Domain\Tool\Tab;

class RecipeRemovedEvent extends  RecipesOrIngredientsRemovedEvent
{
    public function __construct(
        string $userId,
        string $recipeId
    ) {
        parent::__construct(
            userId: $userId,
            ingredientSlugs: Tab::newEmptyTyped('string'),
            recipeIds: Tab::fromArray($recipeId)
        );
    }
}

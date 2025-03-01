<?php

namespace Repas\Repas\Domain\Event;


use Repas\Shared\Domain\Tool\Tab;

class IngredientRemovedEvent extends RecipesOrIngredientsRemovedEvent
{
    public function __construct(
        string $userId,
        string $ingredientSlugs,
    ) {
        parent::__construct(
            userId: $userId,
            ingredientSlugs: Tab::fromArray($ingredientSlugs),
            recipeIds: Tab::newEmptyTyped('string'),
        );
    }
}

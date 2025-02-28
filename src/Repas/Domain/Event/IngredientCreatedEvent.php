<?php

namespace Repas\Repas\Domain\Event;


use Repas\Shared\Domain\Tool\Tab;

readonly class IngredientCreatedEvent extends RecipesOrIngredientsCreatedEvent
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

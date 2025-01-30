<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Meal;
use Repas\Shared\Domain\Tool\UuidGenerator;

class MealBuilder implements Builder
{
    private ?string $id = null;
    private ?string $shoppingListId = null;
    private ?RecipeBuilder $recipeBuilder = null;
    private ?int $serving = null;

    public function setId(?string $id): MealBuilder
    {
        $this->id = $id;
        return $this;
    }

    public function setShoppingListId(?string $shoppingListId): MealBuilder
    {
        $this->shoppingListId = $shoppingListId;
        return $this;
    }

    public function setRecipeBuilder(?RecipeBuilder $recipeBuilder): MealBuilder
    {
        $this->recipeBuilder = $recipeBuilder;
        return $this;
    }

    public function setServing(?int $serving): MealBuilder
    {
        $this->serving = $serving;
        return $this;
    }

    public function build(): Meal
    {
        $this->initialize();
        return Meal::load([
            'id' => $this->id,
            'shopping_list_id' => $this->shoppingListId,
            'recipe' => $this->recipeBuilder->build(),
            'serving' => $this->serving,
        ]);
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->serving ??= 4;
    }

}

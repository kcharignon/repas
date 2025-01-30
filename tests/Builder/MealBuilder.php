<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\UuidGenerator;

class MealBuilder implements Builder
{
    private ?string $id = null;
    private ?string $shoppingListId = null;
    private RecipeBuilder|Recipe|null $recipe = null;
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

    public function setRecipe(RecipeBuilder|Recipe $recipe): MealBuilder
    {
        $this->recipe = $recipe;
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
        $recipe = $this->recipe instanceof Recipe ? $this->recipe : $this->recipe->build();
        return Meal::load([
            'id' => $this->id,
            'shopping_list_id' => $this->shoppingListId,
            'recipe' => $recipe,
            'serving' => $this->serving,
        ]);
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->serving ??= 4;
    }

}

<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\UuidGenerator;

class RecipeRowBuilder implements Builder
{
    private ?string $id = null;
    private ?string $recipeId = null;
    private IngredientBuilder|Ingredient|null $ingredient = null;
    private ?int $quantity = null;
    private ?UnitBuilder $unitBuilder = null;

    public function setId(?string $id): RecipeRowBuilder
    {
        $this->id = $id;
        return $this;
    }

    public function withRecipeId(?string $recipeId): RecipeRowBuilder
    {
        $this->recipeId = $recipeId;
        return $this;
    }

    public function withIngredient(IngredientBuilder|Ingredient|null $ingredientBuilder): RecipeRowBuilder
    {
        $this->ingredient = $ingredientBuilder;
        return $this;
    }

    public function withQuantity(?int $quantity): RecipeRowBuilder
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function withUnit(?UnitBuilder $unitBuilder): RecipeRowBuilder
    {
        $this->unitBuilder = $unitBuilder;
        return $this;
    }

    public function build(): RecipeRow
    {
        $this->initialize();
        return RecipeRow::load([
            'id' => $this->id,
            'recipe_id' => $this->recipeId,
            'ingredient' => $this->ingredient instanceof Ingredient ? $this->ingredient : $this->ingredient->build(),
            'quantity' => $this->quantity,
            'unit' => $this->unitBuilder->build(),
        ]);
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->ingredient ??= new IngredientBuilder()->isEgg();
        $this->quantity ??= 4;
        $this->unitBuilder ??= new UnitBuilder()->isUnite();
    }
}

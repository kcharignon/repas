<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\UuidGenerator;

class RecipeRowBuilder implements Builder
{
    private ?string $id = null;
    private ?string $recipeId = null;
    private ?IngredientBuilder $ingredientBuilder = null;
    private ?int $quantity = null;
    private ?UnitBuilder $unitBuilder = null;

    public function setId(?string $id): RecipeRowBuilder
    {
        $this->id = $id;
        return $this;
    }

    public function setRecipeId(?string $recipeId): RecipeRowBuilder
    {
        $this->recipeId = $recipeId;
        return $this;
    }

    public function setIngredientBuilder(?IngredientBuilder $ingredientBuilder): RecipeRowBuilder
    {
        $this->ingredientBuilder = $ingredientBuilder;
        return $this;
    }

    public function setQuantity(?int $quantity): RecipeRowBuilder
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function setUnitBuilder(?UnitBuilder $unitBuilder): RecipeRowBuilder
    {
        $this->unitBuilder = $unitBuilder;
        return $this;
    }

    public function build(): RecipeRow
    {
        $this->initialize();
        return RecipeRow::load([
            'id' => $this->id,
            'recipeId' => $this->recipeId,
            'ingredient' => $this->ingredientBuilder->build(),
            'quantity' => $this->quantity,
            'unit' => $this->unitBuilder->build(),
        ]);
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
    }
}

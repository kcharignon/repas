<?php

namespace Repas\Repas\Domain\Model;

use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class RecipeRow implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $id,
        private string $recipeId,
        private Ingredient $ingredient,
        private float $quantity,
        private Unit $unit,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getIngredient(): Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function multiplyQuantityBy(float $coefficient): self
    {
        $this->quantity *= $coefficient;
        return $this;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function getRecipeId(): string
    {
        return $this->recipeId;
    }

    public function setRecipeId(string $recipeId): RecipeRow
    {
        $this->recipeId = $recipeId;
        return $this;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            recipeId: $datas['recipe_id'],
            ingredient: $datas['ingredient'],
            quantity: $datas['quantity'],
            unit: $datas['unit'],
        );
    }

    public static function create(
        string $id,
        string $recipeId,
        Ingredient $ingredient,
        float $quantity,
        Unit $unit
    ): self {
        return new self($id, $recipeId, $ingredient, $quantity, $unit);
    }

    public static function copyFromOriginal(
        string $id,
        RecipeRow $originalRow,
        string $recipeId,
    ): self {
        return new self(
            id: $id,
            recipeId: $recipeId,
            ingredient: $originalRow->getIngredient(),
            quantity: $originalRow->getQuantity(),
            unit: $originalRow->getUnit(),
        );
    }

    public function getDepartment(): Department
    {
        return $this->ingredient->getDepartment();
    }
}

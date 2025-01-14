<?php

namespace Repas\Repas\Domain\Model;

class RecipeRow
{
    private function __construct(
        private string $id,
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

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public static function load(array $datas): self
    {
        return new self(
            $datas['id'],
            Ingredient::load($datas['ingredient']),
            $datas['quantity'],
            Unit::load($datas['unit']),
        );
    }

    public static function create(string $id, Ingredient $ingredient, float $quantity, Unit $unit): self
    {
        return new self($id, $ingredient, $quantity, $unit);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ingredient' => $this->ingredient->toArray(),
            'quantity' => $this->quantity,
            'unit' => $this->unit->toArray(),
        ];
    }
}

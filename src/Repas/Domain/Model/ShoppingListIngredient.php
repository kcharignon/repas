<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class ShoppingListIngredient implements ModelInterface
{
    use ModelTrait;


    public function __construct(
        private string                $id,
        private string                $shoppingListId,
        private Ingredient            $ingredient,
        private Unit                  $unit,
        private float                 $quantity,
    ) {
    }

    public static function create(
        string     $shoppingListId,
        Ingredient $ingredient,
        Unit       $unit,
        float      $quantity
    ): self {
        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            ingredient: $ingredient,
            unit: $unit,
            quantity: $quantity,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id : $datas['id'],
            shoppingListId : $datas['shopping_list_id'],
            ingredient : $datas['ingredient'],
            unit : $datas['unit'],
            quantity : $datas['quantity'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShoppingListId(): string
    {
        return $this->shoppingListId;
    }

    public function getIngredient(): Ingredient
    {
        return $this->ingredient;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getDepartment(): Department
    {
        return $this->getIngredient()->getDepartment();
    }

    public function hasIngredientInUnit(Ingredient $ingredient, Unit $unit): bool
    {
        return $this->ingredient->isEqual($ingredient) && $this->unit->isEqual($unit);
    }

    public function addQuantity(float $getQuantity): void
    {
        $this->quantity += $getQuantity;
    }

    public function subtractQuantity(float $getQuantity): void
    {
        $this->quantity -= $getQuantity;
    }
}

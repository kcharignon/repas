<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Repas\Domain\Model\ShoppingListRowStatus as Status;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class ShoppingListRow implements ModelInterface
{
    use ModelTrait;

    public function __construct(
        private string     $id,
        private string     $shoppingListId,
        private Ingredient $ingredient,
        private float      $quantity,
        private Unit       $unit,
        private Status     $status,
    ) {
    }

    public static function create(
        string $shoppingListId,
        Ingredient $ingredient,
        float      $quantity,
    ): self {
        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            ingredient: $ingredient,
            quantity: $quantity,
            unit: $ingredient->getDefaultPurchaseUnit(),
            status: Status::UNCHECKED,
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

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            ingredient: $datas['ingredient'],
            quantity: $datas['quantity'],
            unit: $datas['unit'],
            status: $datas['status'],
        );
    }

    public function addQuantity(float $quantity): void
    {
        $this->quantity += $quantity;
    }
}

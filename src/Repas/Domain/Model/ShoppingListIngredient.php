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
        private string                $ingredientSlug,
        private string                $unitSlug,
        private float                 $quantity,
    ) {
    }

    public static function create(
        string $shoppingListId,
        string $ingredientSlug,
        string $unitSlug,
        float  $quantity
    ): self {
        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            ingredientSlug: $ingredientSlug,
            unitSlug: $unitSlug,
            quantity: $quantity,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id : $datas['id'],
            shoppingListId : $datas['shopping_list_id'],
            ingredientSlug : $datas['ingredient_slug'],
            unitSlug : $datas['unit_slug'],
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

    public function getIngredientSlug(): string
    {
        return $this->ingredientSlug;
    }

    public function getUnitSlug(): string
    {
        return $this->unitSlug;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function addQuantity(float $getQuantity): void
    {
        $this->quantity += $getQuantity;
    }

    public function subtractQuantity(float $getQuantity): void
    {
        $this->quantity -= $getQuantity;
    }

    public function hasIngredient(string $ingredientSlug): bool
    {
        return $this->ingredientSlug === $ingredientSlug;
    }
}

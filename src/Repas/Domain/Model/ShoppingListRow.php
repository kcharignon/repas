<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class ShoppingListRow implements ModelInterface
{
    use ModelTrait;

    public function __construct(
        private string     $id,
        private string     $shoppingListId,
        private string     $ingredientSlug,
        private float      $quantity,
        private string     $unitSlug,
        private bool       $checked,
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
            ingredientSlug: $ingredient->getSlug(),
            quantity: $quantity,
            unitSlug: $ingredient->getDefaultPurchaseUnit()->getSlug(),
            checked: false,
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

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitSlug(): string
    {
        return $this->unitSlug;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            ingredientSlug: $datas['ingredient_slug'],
            quantity: $datas['quantity'],
            unitSlug: $datas['unit_slug'],
            checked: $datas['checked'],
        );
    }

    public function addQuantity(float $quantity): void
    {
        $this->quantity += $quantity;
    }

    public function subtractQuantity(float $quantity): void
    {
        $this->quantity -= $quantity;
    }

    public function tick(): void
    {
        $this->checked = true;
    }

    public function uncheck(): void
    {
        $this->checked = false;
    }
}

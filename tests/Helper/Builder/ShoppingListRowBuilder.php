<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\UuidGenerator;

class ShoppingListRowBuilder implements Builder
{
    private string $id;
    private string $shoppingListId;
    private Ingredient|IngredientBuilder $ingredient;
    private float $quantity;
    private Unit|UnitBuilder $unit;
    private bool $checked;

    public function build(): ShoppingListRow
    {
        $this->initialize();
        return ShoppingListRow::load([
            'id' => $this->id,
            'shopping_list_id' => $this->shoppingListId,
            'ingredient' => $this->ingredient instanceof IngredientBuilder ? $this->ingredient->build() : $this->ingredient,
            'quantity' => $this->quantity,
            'unit' => $this->unit instanceof UnitBuilder ? $this->unit->build() : $this->unit,
            'checked' => $this->checked,
        ]);
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->shoppingListId ??= UuidGenerator::new();
        $this->ingredient ??= new IngredientBuilder()->isEgg();
        $this->quantity ??= 6;
        $this->unit ??= new UnitBuilder()->isUnite();
        $this->checked ??= false;
    }

    public function checked(): self
    {
        $this->checked = true;
        return $this;
    }

    public function withShoppingListId(string $id): self
    {
        $this->shoppingListId = $id;
        return $this;
    }
}

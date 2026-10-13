<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Tests\Helper\Builder\Builder;

class ShoppingListRowDTOBuilder implements Builder
{
    private string $id = 'row-1';
    private string $ingredientName = 'œuf';
    private float $quantity = 6;
    private string $unitSymbol = '';
    private bool $checked = false;

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withIngredientName(string $ingredientName): self
    {
        $this->ingredientName = $ingredientName;
        return $this;
    }

    public function withQuantity(float $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function withUnitSymbol(string $unitSymbol): self
    {
        $this->unitSymbol = $unitSymbol;
        return $this;
    }

    public function checked(): self
    {
        $this->checked = true;
        return $this;
    }

    public function build(): ShoppingListRowDTO
    {
        return new ShoppingListRowDTO($this->id, $this->ingredientName, $this->quantity, $this->unitSymbol, $this->checked);
    }
}

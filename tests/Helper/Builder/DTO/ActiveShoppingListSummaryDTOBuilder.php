<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Tests\Helper\Builder\Builder;

class ActiveShoppingListSummaryDTOBuilder implements Builder
{
    private ?string $shoppingListId = 'shopping-list-1';
    /** @var array<string, float> */
    private array $quantityByIngredientSlug = [];
    /** @var list<string> */
    private array $plannedRecipeIds = [];

    public function withShoppingListId(?string $shoppingListId): self
    {
        $this->shoppingListId = $shoppingListId;
        return $this;
    }

    public function withQuantity(string $ingredientSlug, float $quantity): self
    {
        $this->quantityByIngredientSlug[$ingredientSlug] = $quantity;
        return $this;
    }

    public function withPlannedRecipe(string $recipeId): self
    {
        $this->plannedRecipeIds[] = $recipeId;
        return $this;
    }

    public function withoutShoppingList(): self
    {
        $this->shoppingListId = null;
        return $this;
    }

    public function build(): ActiveShoppingListSummaryDTO
    {
        return new ActiveShoppingListSummaryDTO($this->shoppingListId, $this->quantityByIngredientSlug, $this->plannedRecipeIds);
    }
}

<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

class RecipeInShoppingList implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $shoppingListId,
        private Recipe $recipe,
        private int    $servings,
    ) {
    }

    public static function create(string $shoppingListId, Recipe $recipe, int $servings): static
    {
        return new self($shoppingListId, $recipe, $servings);
    }

    public static function load(array $datas): static
    {
        return new self(
            $datas['shopping_list_id'],
            Recipe::load($datas['recipe']),
            $datas['servings']
        );
    }
}

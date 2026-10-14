<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class Meal implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $id,
        private string $shoppingListId,
        private string $recipeId,
        private int    $serving,
    ) {
    }



    public static function create(string $id, string $shoppingListId, string $recipeId, int $servings): self
    {
        return new self(
            id: $id,
            shoppingListId: $shoppingListId,
            recipeId: $recipeId,
            serving: $servings
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            recipeId: $datas['recipe_id'],
            serving: $datas['serving']
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

    public function getRecipeId(): string
    {
        return $this->recipeId;
    }

    public function getServing(): int
    {
        return $this->serving;
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->recipeId === $recipe->getId();
    }

    public function setServing(int $serving): void
    {
        $this->serving = $serving;
    }
}

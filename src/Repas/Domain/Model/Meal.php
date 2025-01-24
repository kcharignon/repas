<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

class Meal implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $id,
        private string $shoppingListId,
        private Recipe $recipe,
        private int    $serving,
    ) {
    }

    public static function create(string $id, string $shoppingListId, Recipe $recipe, int $servings): static
    {
        return new self($id, $shoppingListId, $recipe, $servings);
    }

    public static function load(array $datas): static
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            recipe: static::loadModel($datas['recipe'], Recipe::class),
            serving: $datas['serving']
        );
    }

    public function typeIs(RecipeType $recipeType): bool
    {
        return $this->recipe->isType($recipeType);
    }
}

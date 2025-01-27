<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;

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

    public function getId(): string
    {
        return $this->id;
    }

    public function getShoppingListId(): string
    {
        return $this->shoppingListId;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getServing(): int
    {
        return $this->serving;
    }

    public function getRecipeType(): RecipeType
    {
        return $this->recipe->getType();
    }

    /**
     * @return Tab<Department>
     */
    public function departmentPresent(): Tab
    {
        return $this->recipe->departmentPresent();
    }

    public function typeIs(RecipeType $recipeType): bool
    {
        return $this->recipe->isType($recipeType);
    }
}

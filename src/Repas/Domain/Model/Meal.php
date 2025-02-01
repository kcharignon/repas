<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class Meal implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $id,
        private string $shoppingListId,
        private Recipe $recipe,
        private int    $serving,
    ) {
    }



    public static function create(string $shoppingListId, Recipe $recipe, int $servings): self
    {
        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            recipe: $recipe,
            serving: $servings
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            recipe: $datas['recipe'],
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

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->recipe->isEqual($recipe);
    }
}

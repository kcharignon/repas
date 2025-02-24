<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class ShoppingListBuilder implements Builder
{
    private ?string $id = null;
    private UserBuilder|User|null $owner = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?ShoppingListStatus $status = null;
    /** @var Tab<Recipe>|null  */
    private ?Tab $recipes = null;
    /** @var Tab<Ingredient>|null  */
    private ?Tab $ingredients = null;

    public function build(): ShoppingList
    {
        $this->initialize();
        $owner = $this->owner instanceof User ? $this->owner : $this->owner->build();
        $shoppingList = ShoppingList::load([
            'id' => $this->id,
            'owner' => $owner,
            'created_at' => $this->createdAt,
            'status' => ShoppingListStatus::ACTIVE, // On met au status ACTIVE pour pouvoir ajouter les recettes
            'meals' => Tab::newEmptyTyped(Meal::class),
            'ingredients' => Tab::newEmptyTyped(ShoppingListIngredient::class),
            'rows' => Tab::newEmptyTyped(ShoppingListRow::class),
        ]);

        // On ajoute les repas(recette)
        foreach ($this->recipes as $recipe) {
            $recipe = $recipe instanceof Recipe ? $recipe : $recipe->build();
            $shoppingList->addMeal($recipe);
        }

        // On passe au status demandé
        if ($this->status !== ShoppingListStatus::ACTIVE) {
            $shoppingList->setStatus($this->status);
        }

        foreach ($this->ingredients as $ingredient) {
            $shoppingList->addIngredient($ingredient);
            $shoppingList->addRow($ingredient, 1);
        }

        return $shoppingList;
    }

    public function withOwner(UserBuilder|User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function addRecipe(RecipeBuilder|Recipe $recipe): self
    {
        if ($recipe instanceof RecipeBuilder) {
            $recipe = $recipe->build();
        }

        $this->recipes ??= Tab::newEmptyTyped(Recipe::class);
        $this->recipes[] = $recipe;
        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->owner ??= new UserBuilder();
        $this->createdAt ??= new DateTimeImmutable();
        $this->status ??= ShoppingListStatus::ACTIVE;
        $this->recipes ??= Tab::newEmptyTyped(Recipe::class);
        $this->ingredients ??= Tab::newEmptyTyped(Ingredient::class);
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function addIngredient(Ingredient|IngredientBuilder $ingredient): self
    {
        $this->ingredients ??= Tab::newEmptyTyped(Ingredient::class);
        $this->ingredients[] = $ingredient instanceof IngredientBuilder ? $ingredient->build() : $ingredient;

        return $this;
    }

    public function withStatus(ShoppingListStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
}

<?php

namespace Repas\Tests\Builder;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class ShoppingListBuilder implements Builder
{
    private ?string $id = null;
    private UserBuilder|User|null $owner = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?bool $locked = null;
    /** @var Tab<Recipe>|null  */
    private ?Tab $recipes = null;

    public function build(): ShoppingList
    {
        $this->initialize();
        $owner = $this->owner instanceof User ? $this->owner : $this->owner->build();
        $shoppingList = ShoppingList::load([
            'id' => $this->id,
            'owner' => $owner,
            'created_at' => $this->createdAt,
            'locked' => $this->locked,
            'meals' => Tab::newEmptyTyped(Meal::class),
            'ingredients' => Tab::newEmptyTyped(ShoppingListIngredient::class),
            'rows' => Tab::newEmptyTyped(ShoppingListRow::class),
        ]);

        // On ajoute les repas(recette)
        foreach ($this->recipes as $recipe) {
            $recipe = $recipe instanceof Recipe ? $recipe : $recipe->build();
            $shoppingList->addMeal($recipe);
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
        $this->locked ??= true;
        $this->recipes ??= Tab::newEmptyTyped(Recipe::class);
    }

    public function unLocked(): self
    {
        $this->locked = false;
        return $this;
    }
}

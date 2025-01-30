<?php

namespace Repas\Tests\Builder;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;

class ShoppingListBuilder implements Builder
{
    private ?string $id = null;
    private ?UserBuilder $owner = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?bool $locked = null;
    /** @var Tab<MealBuilder>|null  */
    private ?Tab $meals = null;

    public function build(): ShoppingList
    {
        $this->initialize();
        return ShoppingList::load([
            'id' => $this->id,
            'owner' => $this->owner->build(),
            'created_at' => $this->createdAt,
            'locked' => $this->locked,
            'meals' => $this->meals->map(fn(MealBuilder $builder) => $builder->build()),
        ]);
    }

    public function withOwner(UserBuilder $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function addRecipe(RecipeBuilder $recipeBuilder, ?int $serving = null): self
    {
        $recipe = $recipeBuilder->build();
        $this->id ??= UuidGenerator::new();
        $mealBuilder = new MealBuilder()
            ->setRecipeBuilder($recipeBuilder)
            ->setServing($serving ?? $recipe->getServing())
            ->setShoppingListId($this->id);

        $this->meals ??= Tab::newEmptyTyped(MealBuilder::class);
        $this->meals->add($mealBuilder);
        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->owner ??= new UserBuilder();
        $this->createdAt ??= new DateTimeImmutable();
        $this->locked ??= true;
        $this->meals ??= Tab::newEmptyTyped(MealBuilder::class);
    }
}

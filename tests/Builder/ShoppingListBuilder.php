<?php

namespace Repas\Tests\Builder;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class ShoppingListBuilder implements Builder
{
    private ?string $id = null;
    private UserBuilder|User|null $owner = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?bool $locked = null;
    /** @var Tab<MealBuilder>|null  */
    private ?Tab $meals = null;

    public function build(): ShoppingList
    {
        $this->initialize();
        $owner = $this->owner instanceof User ? $this->owner : $this->owner->build();
        return ShoppingList::load([
            'id' => $this->id,
            'owner' => $owner,
            'created_at' => $this->createdAt,
            'locked' => $this->locked,
            'meals' => $this->meals->map(fn(MealBuilder $builder) => $builder->build()),
        ]);
    }

    public function withOwner(UserBuilder|User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function addRecipe(RecipeBuilder|Recipe $recipe, ?int $serving = null): self
    {
        if ($recipe instanceof RecipeBuilder) {
            $recipe = $recipe->build();
        }

        $this->id ??= UuidGenerator::new();
        $mealBuilder = new MealBuilder()
            ->setRecipe($recipe)
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

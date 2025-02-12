<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class RecipeBuilder implements Builder
{
    private ?string $id = null;
    private ?string $name = null;
    private ?int $serving = null;
    private UserBuilder|User|null $author = null;
    private RecipeTypeBuilder|RecipeType|null $type = null;
    /** @var Tab<RecipeRowBuilder>|null  */
    private ?Tab $rows = null;


    public function build(): Recipe
    {
        $this->initialize();
        $author = $this->author instanceof User ? $this->author : $this->author->build();
        return Recipe::load([
            'id' => $this->id,
            'name' => $this->name,
            'serving' => $this->serving,
            'author' => $author,
            'type' => $this->type instanceof RecipeType ? $this->type : $this->type->build(),
            'rows' => $this->rows->map(fn(RecipeRowBuilder $row) => $row->build()),
        ]);
    }

    public function isPastaCarbonara(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->name = 'pates carbonara';
        $this->serving = 4;
        $this->type = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isPasta())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(500),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isEgg())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(1),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isThickCremeFraiche())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(250),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isDicedBacon())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(250),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isParmesan())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(100),
        );

        return $this;
    }

    public function isSoftBoiledEggs(): self
    {
        $this->id = UuidGenerator::new();
        $this->name = 'œufs à la coque';
        $this->serving = 2;
        $this->type = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isEgg())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(4),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isBread())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(1),
        );

        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->name ??= 'Gloubiboulga';
        $this->serving ??= 2;
        $this->author ??= new UserBuilder();
        $this->type ??= new RecipeTypeBuilder();
        $this->rows ??= [];
    }

    public function withAuthor(UserBuilder|User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withServing(int $serving): self
    {
        $this->serving = $serving;
        return $this;
    }

    public function addRow(RecipeRowBuilder $row): self
    {
        $this->rows ??= Tab::newEmptyTyped(RecipeRowBuilder::class);
        $this->rows[] = $row;
        return $this;
    }

    public function withRecipeType(RecipeType $recipeType): self
    {
        $this->type = $recipeType;
        return $this;
    }
}

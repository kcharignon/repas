<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class RecipeBuilder implements Builder
{
    private ?string $id = null;
    private ?string $name = null;
    private ?int $serving = null;
    private UserBuilder|User|null $author = null;
    private ?RecipeTypeBuilder $typeBuilder = null;
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
            'type' => $this->typeBuilder->build(),
            'rows' => $this->rows->map(fn(RecipeRowBuilder $row) => $row->build()),
        ]);
    }

    public function isPastaCarbonara(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->name = 'pates carbonara';
        $this->serving = 4;
        $this->typeBuilder = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isPasta())
                ->setUnitBuilder(new UnitBuilder()->isGramme())
                ->setQuantity(500),
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isEgg())
                ->setUnitBuilder(new UnitBuilder()->isPiece())
                ->setQuantity(1),
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isThickCremeFraiche())
                ->setUnitBuilder(new UnitBuilder()->isGramme())
                ->setQuantity(250),
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isDicedBacon())
                ->setUnitBuilder(new UnitBuilder()->isGramme())
                ->setQuantity(250),
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isParmesan())
                ->setUnitBuilder(new UnitBuilder()->isGramme())
                ->setQuantity(100),
        );

        return $this;
    }

    public function isSoftBoiledEggs(): self
    {
        $this->id = UuidGenerator::new();
        $this->name = 'œufs à la coque';
        $this->serving = 2;
        $this->typeBuilder = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isEgg())
                ->setUnitBuilder(new UnitBuilder()->isPiece())
                ->setQuantity(4),
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isBread())
                ->setUnitBuilder(new UnitBuilder()->isPiece())
                ->setQuantity(1),
        );

        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->name ??= 'Gloubiboulga';
        $this->serving ??= 2;
        $this->author ??= new UserBuilder();
        $this->typeBuilder ??= new RecipeTypeBuilder();
        $this->rows ??= [];
    }

    public function withAuthor(UserBuilder|User $author): self
    {
        $this->author = $author;
        return $this;
    }
}

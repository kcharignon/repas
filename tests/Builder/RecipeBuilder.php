<?php

namespace Builder;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Builder\Builder;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Builder\RecipeRowBuilder;
use Repas\Tests\Builder\RecipeTypeBuilder;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Builder\UserBuilder;

class RecipeBuilder implements Builder
{
    private ?string $id = null;
    private ?string $name = null;
    private ?int $serving = null;
    private ?UserBuilder $authorBuilder = null;
    private ?RecipeTypeBuilder $typeBuilder = null;
    /** @var Tab<RecipeRowBuilder>|null  */
    private ?Tab $rows = null;


    public function build(): Recipe
    {
        $this->initialize();
        return Recipe::load([
            'id' => $this->id,
            'name' => $this->name,
            'serving' => $this->serving,
            'author' => $this->authorBuilder->build(),
            'type' => $this->typeBuilder->build(),
            'rows' => $this->rows->map(fn(RecipeRowBuilder $row) => $row->build()),
        ]);
    }

    public function isPastaCarbonara(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->name ??= 'pates carbonara';
        $this->serving ??= 4;
        $this->authorBuilder ??= new UserBuilder();
        $this->typeBuilder ??= new RecipeTypeBuilder()->isMeal();
        $this->rows ??= [
            new RecipeRowBuilder()
                ->setRecipeId($this->id)
                ->setIngredientBuilder(new IngredientBuilder()->isPasta())
                ->setUnitBuilder(new UnitBuilder()->isGramme())
                ->setQuantity(500),
        ];

        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->name ??= 'Gloubiboulga';
        $this->serving ??= 2;
        $this->authorBuilder ??= new UserBuilder();
        $this->typeBuilder ??= new RecipeTypeBuilder();
        $this->rows ??= [];
    }
}

<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Builder\DepartmentBuilder;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;

class IngredientRepositoryTest extends DatabaseTestCase
{
    private readonly IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->build();

        // Act
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);

        // Arrange
        $baby = new DepartmentBuilder()->isBaby()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $ingredient->setName('nouveau nom');
        $ingredient->setImage('file://image/nouvelle/nouveau.jpg');
        $ingredient->setDepartment($baby);
        $ingredient->setDefaultCookingUnit($gramme);
        $ingredient->setDefaultPurchaseUnit($gramme);

        // Act
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);
    }

    public function testGetByDepartment(): void
    {
        // Arrange
        // Compte le nombre d'ingrédients au rayon bebe
        $babyDepartmentBuilder = new DepartmentBuilder()->isBaby();
        $before = $this->ingredientRepository->findByDepartment($babyDepartmentBuilder->build());
        $count = $before->count();
        // Ajoute un ingredient au rayon bébé
        $ingredient = new IngredientBuilder()->setDepartment($babyDepartmentBuilder)->build();
        $this->ingredientRepository->save($ingredient);

        // Act
        $actual = $this->ingredientRepository->findByDepartment($babyDepartmentBuilder->build());

        // Assert
        $this->assertEquals($count + 1, $actual->count());
    }
}

<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Entity\User;

class RecipeRepositoryTest extends DatabaseTestCase
{
    private RecipeRepository $recipeRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);

        // Arrange
        $recipe->setServing(10);
        $recipe->setName("nouveau petit nom");
        $recipe->setType(new RecipeTypeBuilder()->isDessert()->build());
        $recipe->setRows(Tab::fromArray(new RecipeRowBuilder()->withRecipeId($recipe->getId())->build()));

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);
    }

    public function testFindByAuthor(): void
    {
        // Arrange
        $author = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $recipes = $this->recipeRepository->findByAuthor($author);

        // Assert
        $this->assertCount(73, $recipes);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testFindByIngredient(): void
    {
        // Act
        $actual = $this->recipeRepository->findByIngredient(new IngredientBuilder()->isPasta()->build());

        // Assert
        $this->assertCount(4, $actual);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $actual);
    }

    public function testDelete(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);

        // Act
        $this->recipeRepository->delete($recipe);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear(); // Nettoie le cache Doctrine

        // Assert
        $this->expectException(\Exception::class); // Adapte si une exception spécifique est levée
        $this->recipeRepository->findOneById($recipe->getId());
    }

    public function testFindByAuthorAndType(): void
    {
        // Arrange
        $author = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $type = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $recipes = $this->recipeRepository->findByAuthorAndType($author, $type);

        // Assert
        $this->assertCount(12, $recipes);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testFindBy(): void
    {
        // Arrange
        $criteria = ['name' => 'pates carbonara'];
        $orderBy = ['serving' => 'DESC'];

        // Act
        $recipes = $this->recipeRepository->findBy($criteria, $orderBy);

        // Assert
        $this->assertNotEmpty($recipes);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }
}

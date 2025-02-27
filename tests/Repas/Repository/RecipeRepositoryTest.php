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


    public function findByNotAuthorAndNotCopyDataProvider(): array
    {
        return [
            "alexiane.sichi@gmail.com" => ['alexiane.sichi@gmail.com', 0],
            "john.doe@gmail.com" => ['john.doe@gmail.com', 73],
        ];
    }

    /**
     * @dataProvider findByNotAuthorAndNotCopyDataProvider
     */
    public function testFindByNotAuthorAndNotCopy(string $authorEmail, int $expectedCount): void
    {
        // Act
        $user = $this->userRepository->findOneByEmail($authorEmail);
        $actual = $this->recipeRepository->findByNotAuthorAndNotCopy($user);

        // Assert
        $this->assertCount($expectedCount, $actual);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $actual);
    }
}

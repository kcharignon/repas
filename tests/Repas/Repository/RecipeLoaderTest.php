<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Loader\RecipeLoader;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;

class RecipeLoaderTest extends DatabaseTestCase
{
    private readonly RecipeLoader $recipeLoader;
    private readonly UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeLoader = static::getContainer()->get(RecipeLoader::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testFindByAuthorAndType(): void
    {
        // Arrange
        $bich = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $dessert = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $result = $this->recipeLoader->findByAuthorAndType($bich, $dessert);

        // Assert
        $this->assertCount(12, $result);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->getType()->isEqual($dessert));
            $this->assertTrue($recipe->getAuthor()->isEqual($bich));
            $this->assertEmpty($recipe->getRows());
        }
    }

    public function testFindBy(): void
    {
        // Arrange
        $bich = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $dessert = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $result = $this->recipeLoader->findBy(['authorId' => $bich->getId(), 'typeSlug' => 'dessert']);

        // Assert
        $this->assertCount(12, $result);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->getType()->isEqual($dessert));
            $this->assertTrue($recipe->getAuthor()->isEqual($bich));
            $this->assertEmpty($recipe->getRows());
        }
    }

    public function testFindByNotAuthorAndNotCopy(): void
    {
        // Arrange
        $john = $this->userRepository->findOneByEmail('john.doe@gmail.com');

        // Act
        $result = $this->recipeLoader->findByNotAuthorAndNotCopy($john);

        // Assert
        $this->assertCount(73, $result);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->isOriginal());
            $this->assertFalse($recipe->getAuthor()->isEqual($john));
            $this->assertEmpty($recipe->getRows());
        }
    }
}

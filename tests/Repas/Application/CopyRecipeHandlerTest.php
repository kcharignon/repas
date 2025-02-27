<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class CopyRecipeHandlerTest extends TestCase
{
    private readonly CopyRecipeHandler $handler;
    private readonly RecipeRepository $recipeRepository;
    private readonly UserRepository $userRepository;
    private User $user;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('owner-id')->build();
        $this->user = new UserBuilder()->withId('user-id')->build();
        $this->userRepository = new UserInMemoryRepository([$owner, $this->user]);
        $this->recipeRepository = new RecipeInMemoryRepository([
            new RecipeBuilder()->withId('recipe-id')->isPastaCarbonara()->withAuthor($owner)->build(),
        ]);

        $this->handler = new CopyRecipeHandler(
            $this->recipeRepository,
            $this->userRepository,
        );
    }


    public function testSuccessfullyHandleCopyRecipe(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('recipe-id', 'user-id');

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->recipeRepository->findByAuthor($this->user)->reset();
        $expected = new RecipeBuilder()->withId($actual->getId())->isPastaCarbonara()->withAuthor($this->user)->build();

        RepasAssert::assertRecipe($expected, $actual, ['RecipeRow' => ['id']]);
    }

    public function testFailedHandleCopyRecipeUnknownUser(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('recipe-id', 'not-found');

        // Assert
        $this->expectExceptionObject(UserException::NotFound('not-found'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleCopyRecipeUnknownRecipe(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('not-found', 'user-id');

        // Assert
        $this->expectExceptionObject(RecipeException::NotFound('not-found'));

        // Act
        ($this->handler)($command);
    }
}

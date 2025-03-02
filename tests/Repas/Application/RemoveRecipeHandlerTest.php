<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\RemoveRecipe\RemoveRecipeHandler;
use Repas\Repas\Domain\Event\RecipeRemovedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class RemoveRecipeHandlerTest extends TestCase
{
    private readonly RemoveRecipeHandler $handler;
    private readonly RecipeRepository $recipeRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $recipeFree = new RecipeBuilder()->withId('recipe-free')->withAuthor(new UserBuilder()->withId('user-id'))->isSoftBoiledEggs()->build();
        $recipeUsed = new RecipeBuilder()->withId('recipe-used')->isSoftBoiledEggs()->build();
        $this->recipeRepository = new RecipeInMemoryRepository([$recipeFree, $recipeUsed]);

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->addRecipe($recipeUsed)->build()
        ]);

        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new RemoveRecipeHandler(
            $this->recipeRepository,
            $this->shoppingListRepository,
            $this->eventDispatcher
        );
    }

    public function testSuccessfullyHandleRemoveRecipe(): void
    {
        // Arrange
        $command = new RemoveRecipeCommand('recipe-free');

        // Act
        ($this->handler)($command);

        // Assert
        $expectedEvent = new RecipeRemovedEvent('user-id', 'recipe-free');
        $actualEvent = $this->eventDispatcher->getLastEventDispatched();
        $this->assertEquals($expectedEvent, $actualEvent);

        $this->expectExceptionObject(RecipeException::notFound('recipe-free'));
        $this->recipeRepository->findOneById('recipe-free');
    }

    public function testFailedHandleRemoveRecipeAlreadyUsedInShoppingList(): void
    {
        // Arrange
        $command = new RemoveRecipeCommand('recipe-used');

        // Assert
        $this->expectExceptionObject(RecipeException::cannotRemoveExistInShoppingList());

        // Act
        ($this->handler)($command);
    }
}

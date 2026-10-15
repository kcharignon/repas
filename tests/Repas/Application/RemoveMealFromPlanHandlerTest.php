<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Model\User;

class RemoveMealFromPlanHandlerTest extends TestCase
{
    private readonly removeMealFromPlanHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly ConversionRepository $conversionRepository;
    private readonly UnitRepository $unitRepository;
    private readonly ConversionService $conversionService;

    private User $user;
    private Recipe $recipe;
    private ShoppingList $shoppingList;

    protected function setUp(): void
    {
        $this->user = new UserBuilder()
            ->withId("user-id")
            ->build();
        $this->recipe = new RecipeBuilder()
            ->withId("recipe-id")
            ->withAuthor($this->user)
            ->isBasqueCake()
            ->build();
        $this->shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addRecipe($this->recipe)
            ->build();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->build(),
            new ConversionBuilder()->isBlockToGrammeForButter()->build(),
        ]);
        $this->unitRepository = new UnitInMemoryRepository();

        $this->conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository,
        );
        $this->handler = new removeMealFromPlanHandler(
            $this->shoppingListRepository,
            $this->recipeRepository,
            $this->conversionService
        );
    }

    public function testSuccessfullyHandleRemoveMealFromPlan(): void
    {
        // Arrange
        $this->recipeRepository->save($this->recipe);
        $this->shoppingListRepository->save($this->shoppingList);
        $command = new removeMealFromPlanCommand(
            "user-id",
            "recipe-id",
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->build();
        $actual = $this->shoppingListRepository->findOneActivateByOwner($this->user->getId());
        RepasAssert::assertShoppingList($expected, $actual);
    }

    public function testSuccessfullyHandleRemoveMealFromPlanWithEmptyShoppingList(): void
    {
        // Arrange
        $this->recipeRepository->save($this->recipe);
        $this->shoppingListRepository->save(new ShoppingListBuilder()
            ->withOwner($this->user)
            ->withId('shopping-list-id')
            ->build()
        );
        $command = new removeMealFromPlanCommand(
            "user-id",
            "recipe-id",
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->build();
        $actual = $this->shoppingListRepository->findOneActivateByOwner($this->user->getId());
        RepasAssert::assertShoppingList($expected, $actual);
    }

    public function testFailedHandleRemoveMealFromPlanUnknownRecipe(): void
    {
        // Arrange
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($this->user)->build());
        $command = new removeMealFromPlanCommand(
            "user-id",
            "recipe-id",
        );

        // Assert
        $this->expectExceptionObject(RecipeException::notFound('recipe-id'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleRemoveMealFromPlanNoActiveShoppingList(): void
    {
        // Arrange
        $this->recipeRepository->save($this->recipe);
        $command = new removeMealFromPlanCommand(
            "user-id",
            "recipe-id",
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnOutdatedVersionAndRemovesNothing(): void
    {
        // Arrange : la liste est à la version 1, le client croit à la 2
        $this->recipeRepository->save($this->recipe);
        $this->shoppingListRepository->save($this->shoppingList);
        $command = new removeMealFromPlanCommand('user-id', 'recipe-id', expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated('shopping-list-id'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertTrue($this->shoppingList->hasRecipe($this->recipe));
        }
    }
}

<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses utilisateurs, recettes et listes (la base de test est vide au départ).
 */
class ShoppingListRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->savedUser();
        $firstRecipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $secondRecipe = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($firstRecipe)
            ->build()
        ;

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingList->addMeal('second-meal-id', $secondRecipe, 4);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        //Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingList->removeMeal($firstRecipe);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        foreach ($secondRecipe->getRows() as $recipeRow) {
            $shoppingList->addRow($recipeRow->getIngredient()->getSlug(), $recipeRow->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $recipeRow->getQuantity());
        }

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Act
        $this->shoppingListRepository->delete($shoppingList);

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound($shoppingList->getId()));
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
    }

    public function testFindByOwner(): void
    {
        // Arrange
        $user = $this->savedUser();
        $other = $this->savedUser();
        $expectedIds = [];
        foreach (ShoppingListStatus::cases() as $status) {
            $expectedIds[] = $this->savedShoppingList($user, $status)->getId();
        }
        $this->savedShoppingList($other, ShoppingListStatus::ACTIVE);

        // Act
        $shoppingLists = $this->shoppingListRepository->findByOwner($user->getId());

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertCount(3, $shoppingLists);
        $this->assertEqualsCanonicalizing($expectedIds, $this->idsOf($shoppingLists));
        foreach ($shoppingLists as $shoppingList) {
            $this->assertSame($user->getId(), $shoppingList->getOwnerId());
        }
    }

    public function testFindOneActivateByOwner(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::COMPLETED);
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());

        // Assert
        $this->assertInstanceOf(ShoppingList::class, $shoppingList);
        $this->assertSame($active->getId(), $shoppingList->getId());
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingList->getStatus());
        $this->assertSame($user->getId(), $shoppingList->getOwnerId());
    }

    public function testFindOneActivateByOwnerReturnsNullWithoutActiveList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());

        // Assert
        $this->assertNull($shoppingList);
    }

    public function testHasActiveShoppingList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->hasActiveShoppingList($user->getId()));
    }

    public function testHasNoActiveShoppingListWithOnlyPausedOrOtherUsersLists(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::COMPLETED);
        $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // Act & Assert
        $this->assertFalse($this->shoppingListRepository->hasActiveShoppingList($user->getId()));
    }

    public function testIsIngredientUsedTellsIfAnyShoppingListHoldsTheIngredient(): void
    {
        // Arrange
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($pasta)->build());
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->isIngredientUsed($pasta->getSlug()));
        $this->assertFalse($this->shoppingListRepository->isIngredientUsed($egg->getSlug()));
    }

    public function testFindIdsByOwnerAndStatus(): void
    {
        // Arrange
        $user = $this->savedUser();
        $status = ShoppingListStatus::ACTIVE;
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // Act
        $ids = $this->shoppingListRepository->findIdsByOwnerAndStatus($user->getId(), $status);

        // Assert
        $this->assertSame([$active->getId()], $ids);
    }

    public function testFindOneByMealId(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $generator = new ControlledUuidGenerator(['meal-id-0']);
        $shoppingList = new ShoppingListBuilder($generator)->withOwner($user)->addRecipe($recipe)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        $actual = $this->shoppingListRepository->findOneByMealId('meal-id-0');

        // Assert
        RepasAssert::assertShoppingList($shoppingList, $actual);
    }

    public function testIsRecipePlannedTellsIfAnyMealUsesTheRecipe(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $otherRecipe = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build());

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->isRecipePlanned($recipe->getId()));
        $this->assertFalse($this->shoppingListRepository->isRecipePlanned($otherRecipe->getId()));
    }

    /**
     * @param Tab<ShoppingList> $shoppingLists
     * @return list<string>
     */
    private function idsOf(Tab $shoppingLists): array
    {
        return array_values(array_map(static fn(ShoppingList $list): string => $list->getId(), $shoppingLists->toArray()));
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedShoppingList(User $owner, ShoppingListStatus $status): ShoppingList
    {
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->withStatus($status)->build();
        $this->shoppingListRepository->save($shoppingList);

        return $shoppingList;
    }
}

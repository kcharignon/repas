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
 * Chaque test crée ses utilisateurs, recettes et listes (aucune fixture globale, roadmap « Dette de tests »).
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
        $shoppingList->addMeal('second-meal-id', $secondRecipe);

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
        foreach ($shoppingList->getIngredients() as $shopListIngredient) {
            $shoppingList->addRow($shopListIngredient->getIngredient(), $shopListIngredient->getQuantity());
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
        $shoppingLists = $this->shoppingListRepository->findByOwner($user);

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertCount(3, $shoppingLists);
        $this->assertEqualsCanonicalizing($expectedIds, $this->idsOf($shoppingLists));
        foreach ($shoppingLists as $shoppingList) {
            RepasAssert::assertUser($shoppingList->getOwner(), $user);
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
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user);

        // Assert
        $this->assertInstanceOf(ShoppingList::class, $shoppingList);
        $this->assertSame($active->getId(), $shoppingList->getId());
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingList->getStatus());
        RepasAssert::assertUser($shoppingList->getOwner(), $user);
    }

    public function testFindOneActivateByOwnerReturnsNullWithoutActiveList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user);

        // Assert
        $this->assertNull($shoppingList);
    }

    public function testFindByIngredient(): void
    {
        // Arrange
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->addIngredient($pasta)->build();
        $this->shoppingListRepository->save($shoppingList);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);

        // Act
        $withPasta = $this->shoppingListRepository->findByIngredient($pasta);
        $withEgg = $this->shoppingListRepository->findByIngredient($egg);

        // Assert
        $this->assertSame([$shoppingList->getId()], $this->idsOf($withPasta));
        $this->assertCount(0, $withEgg);
    }

    public function testFindByOwnerAndStatus(): void
    {
        // Arrange
        $user = $this->savedUser();
        $status = ShoppingListStatus::ACTIVE;
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // Act
        $shoppingLists = $this->shoppingListRepository->findByOwnerAndStatus($user, $status);

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertSame([$active->getId()], $this->idsOf($shoppingLists));
        foreach ($shoppingLists as $shoppingList) {
            RepasAssert::assertUser($shoppingList->getOwner(), $user);
            $this->assertEquals($status, $shoppingList->getStatus());
        }
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

    public function testFindByRecipe(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $otherRecipe = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        $shoppingLists = $this->shoppingListRepository->findByRecipe($recipe);

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertSame([$shoppingList->getId()], $this->idsOf($shoppingLists));
        $this->assertCount(0, $this->shoppingListRepository->findByRecipe($otherRecipe));
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

<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Builder\RecipeBuilder;
use Repas\Tests\Builder\ShoppingListBuilder;
use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;

class ShoppingListRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $userBuilder = new UserBuilder()->fromModel($user);
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($userBuilder)
            ->addRecipe(new RecipeBuilder()->isPastaCarbonara())
            ->build()
        ;

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->getOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($actual, $shoppingList);

    }


}

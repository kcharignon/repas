<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UncheckLineOnShoppingList\UncheckLineOnShoppingListCommand;
use Repas\Repas\Application\UncheckLineOnShoppingList\UncheckLineOnShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UncheckLineOnShoppingListHandlerTest extends TestCase
{
    private readonly UncheckLineOnShoppingListHandler $handler;
    private readonly ShoppingListRowRepository $shoppingListRowRepository;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $this->shoppingListRowRepository = new ShoppingListRowInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();

        $this->handler = new UncheckLineOnShoppingListHandler(
            $this->shoppingListRowRepository,
            $this->shoppingListRepository,
        );

        $shoppingListRow = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->checked()
            ->build();
        $this->shoppingListRowRepository->save($shoppingListRow);
    }

    public function testHandleSuccessfullyUncheckLineOnShoppingList(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->withStatus(ShoppingListStatus::COMPLETED)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new UncheckLineOnShoppingListCommand('shopping-list-row-id');

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListRowBuilder()->withId('shopping-list-row-id')->withShoppingListId('shopping-list-id')->build();
        $actual = $this->shoppingListRowRepository->findOneById('shopping-list-row-id');
        RepasAssert::assertShoppingListRow($expected, $actual);

        $shoppingListActual = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingListActual->getStatus());
    }

    public function testHandleFailedUncheckLineOnShoppingListUnknownRow(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->withStatus(ShoppingListStatus::COMPLETED)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new UncheckLineOnShoppingListCommand('shopping-list-row-id-not-found');

        // Assert
        $this->expectExceptionObject(ShoppingListRowException::notFound('shopping-list-row-id-not-found'));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedUncheckLineOnShoppingListUnknownShoppingList(): void
    {
        // Arrange
        $command = new UncheckLineOnShoppingListCommand('shopping-list-row-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('shopping-list-id'));

        // Act
        ($this->handler)($command);
    }
}

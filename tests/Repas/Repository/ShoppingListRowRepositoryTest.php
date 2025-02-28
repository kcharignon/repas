<?php

namespace Repas\Tests\Repas\Repository;

use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;

class ShoppingListRowRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRowRepository $shoppingListRowRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRowRepository = static::getContainer()->get(ShoppingListRowRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow = new ShoppingListRowBuilder()
            ->withShoppingListId($shoppingList->getId())
            ->build();

        // Act
        $this->shoppingListRowRepository->save($shoppingListRow);

        // Assert
        $actual = $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
        RepasAssert::assertShoppingListRow($shoppingListRow, $actual);

        // Arrange
        $shoppingListRow->addQuantity(10);

        // Act
        $this->shoppingListRowRepository->save($shoppingListRow);

        // Assert
        $actual = $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
        RepasAssert::assertShoppingListRow($shoppingListRow, $actual);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListId($shoppingList->getId());
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $this->expectException(\Exception::class); // Adapte si une exception spécifique est levée
        $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
    }

    public function testFindByShoppingListId(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);

        // Act
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());

        // Assert
        $this->assertCount(2, $rows);
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingListRow::class), $rows);
    }

    public function testDeleteByShoppingListIdExceptIds(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow3 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);
        $this->shoppingListRowRepository->save($shoppingListRow3);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListIdExceptIds(
            $shoppingList->getId(),
            Tab::fromArray([$shoppingListRow1->getId(), $shoppingListRow2->getId()])
        );

        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(ShoppingListRow::class, $rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow1->getId()));
        $this->assertInstanceOf(ShoppingListRow::class, $rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow2->getId()));
        $this->assertNull($rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow3->getId()));
    }

    public function testDeleteByShoppingListId(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListId($shoppingList->getId());
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());
        $this->assertCount(0, $rows);
    }
}

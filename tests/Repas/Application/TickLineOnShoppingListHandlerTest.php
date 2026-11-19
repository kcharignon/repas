<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListHandler;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Model\RowCheckSource;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\RepasAssert;

class TickLineOnShoppingListHandlerTest extends TestCase
{
    private const string NOW = '2026-11-19 10:30:00';

    private readonly TickLineOnShoppingListHandler $handler;
    private readonly ShoppingListRowRepository $shoppingListRowRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->shoppingListRowRepository = new ShoppingListRowInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new TickLineOnShoppingListHandler(
            $this->shoppingListRowRepository,
            $this->shoppingListRepository,
            $this->eventDispatcher,
            new FrozenClock(new \DateTimeImmutable(self::NOW)),
        );

        $shoppingListRow = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->build();
        $this->shoppingListRowRepository->save($shoppingListRow);
    }

    public function testTickLineOnShoppingSuccess(): void
    {
        // Arrange
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id');

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->checked()
            ->build();
        $actual = $this->shoppingListRowRepository->findOneById('shopping-list-row-id');
        RepasAssert::assertShoppingListRow($expected, $actual);

        $actualEvent = $this->eventDispatcher->getLastEventDispatched();
        $expectedEvent = new LineTickedEvent('shopping-list-id');
        $this->assertEquals($expectedEvent, $actualEvent);
    }

    public function testTheTickedLineRemembersItWasTickedByHandAndWhen(): void
    {
        // Act (ADR 0041)
        ($this->handler)(new TickLineOnShoppingListCommand('shopping-list-row-id'));

        // Assert : cochée à la main, à l'instant de l'horloge
        $row = $this->shoppingListRowRepository->findOneById('shopping-list-row-id');
        $this->assertSame(RowCheckSource::MANUAL, $row->getCheckedBy());
        $this->assertEquals(new \DateTimeImmutable(self::NOW), $row->getCheckedAt());
    }

    public function testHandleFailedTickLineOnShoppingUnknownRow(): void
    {
        // Arrange
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id-not-found');

        // Assert
        $this->expectExceptionObject(ShoppingListRowException::notFound('shopping-list-row-id-not-found'));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesALineOfASentListAndTicksNothing(): void
    {
        // Arrange : une liste envoyée au drive n'est plus modifiable (ADR 0023)
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('shopping-list-id')->withStatus(ShoppingListStatus::SENT)->build()
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::sent('shopping-list-id'));

        // Act
        try {
            ($this->handler)(new TickLineOnShoppingListCommand('shopping-list-row-id'));
        } finally {
            $this->assertFalse($this->shoppingListRowRepository->findOneById('shopping-list-row-id')->isChecked());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}

<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListArchiveInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class RemoveShoppingListHandlerTest extends TestCase
{
    private const string NOW = '2026-11-19 10:30:00';

    private readonly RemoveShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly ShoppingListArchiveInMemoryRepository $archiveRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->archiveRepository = new ShoppingListArchiveInMemoryRepository();
        $this->handler = new RemoveShoppingListHandler(
            $this->shoppingListRepository,
            $this->archiveRepository,
            new FrozenClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    public function testHandleSuccessfullyRemoveShoppingList(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new RemoveShoppingListCommand('shopping-list-id');

        // Act
        ($this->handler)($command);

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('shopping-list-id'));
        $this->shoppingListRepository->findOneById('shopping-list-id');
    }

    public function testTheListIsArchivedBeforeItIsRemovedWithTheCauseAndTheClockTime(): void
    {
        // Arrange (ADR 0041)
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('shopping-list-id')->build());

        // Act
        ($this->handler)(new RemoveShoppingListCommand('shopping-list-id'));

        // Assert : une copie, demandée par la personne par défaut
        $this->assertCount(1, $this->archiveRepository->archived);
        $archive = $this->archiveRepository->archived[0];
        $this->assertSame('shopping-list-id', $archive['shoppingListId']);
        $this->assertSame(ShoppingListArchiveCause::DELETED_BY_USER, $archive['cause']);
        $this->assertEquals(new \DateTimeImmutable(self::NOW), $archive['at']);
        $this->assertNotSame('', $archive['archiveId']);
    }

    public function testTheCleanUpOfACompletedListSaysSoInItsArchive(): void
    {
        // Arrange
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('shopping-list-id')->build());

        // Act
        ($this->handler)(new RemoveShoppingListCommand('shopping-list-id', ShoppingListArchiveCause::CLEANED_AFTER_COMPLETION));

        // Assert
        $this->assertSame(ShoppingListArchiveCause::CLEANED_AFTER_COMPLETION, $this->archiveRepository->archived[0]['cause']);
    }

    public function testHandleFailedRemoveShoppingListUnknownShoppingList(): void
    {
        // Arrange
        $command = new RemoveShoppingListCommand('shopping-list-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('shopping-list-id'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            // Une liste inconnue ne laisse aucune archive
            $this->assertSame([], $this->archiveRepository->archived);
        }
    }
}

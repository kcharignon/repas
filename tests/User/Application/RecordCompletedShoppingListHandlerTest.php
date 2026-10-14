<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\RecordCompletedShoppingList\RecordCompletedShoppingListCommand;
use Repas\User\Application\RecordCompletedShoppingList\RecordCompletedShoppingListHandler;
use Repas\User\Domain\Exception\UserException;

class RecordCompletedShoppingListHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private RecordCompletedShoppingListHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository([new UserBuilder()->withId('user-id')->build()]);
        $this->handler = new RecordCompletedShoppingListHandler($this->userRepository);
    }

    public function testRecordsTheCompletedListInTheOwnerStatistics(): void
    {
        // Given
        $createdAt = new DateTimeImmutable('2026-10-14 09:00:00');

        // When
        ($this->handler)(new RecordCompletedShoppingListCommand('user-id', 'list-id', $createdAt, 2, 5, 13));

        // Then
        $this->assertSame(
            ['list-id' => ['createdAt' => $createdAt, 'meals' => 2, 'ingredients' => 5, 'rows' => 13]],
            $this->userRepository->findOneById('user-id')->getShoppingListStats(),
        );
    }

    public function testCompletingTheSameListTwiceKeepsASingleEntry(): void
    {
        // Given
        $createdAt = new DateTimeImmutable('2026-10-14 09:00:00');

        // When : une liste terminée, réactivée puis terminée de nouveau
        ($this->handler)(new RecordCompletedShoppingListCommand('user-id', 'list-id', $createdAt, 2, 5, 13));
        ($this->handler)(new RecordCompletedShoppingListCommand('user-id', 'list-id', $createdAt, 3, 6, 14));

        // Then
        $stats = $this->userRepository->findOneById('user-id')->getShoppingListStats();
        $this->assertCount(1, $stats);
        $this->assertSame(3, $stats['list-id']['meals']);
    }

    public function testRefusesAnUnknownUser(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::NotFound('unknown'));

        // When
        ($this->handler)(new RecordCompletedShoppingListCommand('unknown', 'list-id', new DateTimeImmutable(), 0, 0, 0));
    }
}

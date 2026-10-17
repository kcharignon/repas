<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class ReopenShoppingListHandlerTest extends TestCase
{
    private readonly ReopenShoppingListHandler $handler;
    private readonly ShoppingListInMemoryRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('owner-id')->build();
        $other = new UserBuilder()->withId('other-id')->build();
        $egg = new IngredientBuilder()->isEgg();

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('sent')->withOwner($owner)->addIngredient($egg)->withStatus(Status::SENT)
                ->withSentAt(new DateTimeImmutable('2026-10-17 08:00:00'))->build(),
            new ShoppingListBuilder()->withId('active')->withOwner($owner)->addIngredient($egg)->build(),
            new ShoppingListBuilder()->withId('paused')->withOwner($owner)->withStatus(Status::PAUSED)->build(),
            new ShoppingListBuilder()->withId('active-of-other')->withOwner($other)->build(),
        ]);

        $this->handler = new ReopenShoppingListHandler($this->shoppingListRepository);
    }

    private function statusOf(string $id): Status
    {
        return $this->shoppingListRepository->findOneById($id)->getStatus();
    }

    public function testReopensTheSentListAndPausesTheOwnersActiveList(): void
    {
        // When
        ($this->handler)(new ReopenShoppingListCommand('sent'));

        // Then
        $this->assertSame(Status::ACTIVE, $this->statusOf('sent'));
        $this->assertNull($this->shoppingListRepository->findOneById('sent')->getSentAt());
        $this->assertSame(Status::PAUSED, $this->statusOf('active'));
        $this->assertSame(Status::PAUSED, $this->statusOf('paused'));
        $this->assertSame(Status::ACTIVE, $this->statusOf('active-of-other'));
    }

    public function testReopensTheSentListWhenTheOwnerHasNoActiveList(): void
    {
        // Given
        $this->shoppingListRepository->delete($this->shoppingListRepository->findOneById('active'));

        // When
        ($this->handler)(new ReopenShoppingListCommand('sent'));

        // Then
        $this->assertSame(Status::ACTIVE, $this->statusOf('sent'));
    }

    #[DataProvider('notSentLists')]
    public function testRefusesAListThatIsNotSentAndTouchesNoOtherList(string $id): void
    {
        // Given
        $status = $this->statusOf($id);

        // Then
        $this->expectExceptionObject(ShoppingListException::notSent($id));

        // When
        try {
            ($this->handler)(new ReopenShoppingListCommand($id));
        } finally {
            $this->assertSame($status, $this->statusOf($id));
            $this->assertSame(Status::ACTIVE, $this->statusOf('active'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notSentLists(): iterable
    {
        yield 'active' => ['active'];
        yield 'en pause' => ['paused'];
    }

    public function testRefusesAnOutdatedVersionAndChangesNothing(): void
    {
        // Given : la liste est à la version 1, le client croit à la 2
        $command = new ReopenShoppingListCommand('sent', expectedVersion: 2);

        // Then
        $this->expectExceptionObject(ShoppingListException::outdated('sent'));

        // When
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([Status::SENT, Status::ACTIVE], [$this->statusOf('sent'), $this->statusOf('active')]);
        }
    }

    public function testFailsOnAnUnknownList(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('unknown'));

        // When
        ($this->handler)(new ReopenShoppingListCommand('unknown'));
    }
}

<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListCommand;
use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class SendShoppingListHandlerTest extends TestCase
{
    private readonly SendShoppingListHandler $handler;
    private readonly ShoppingListInMemoryRepository $shoppingListRepository;
    private readonly DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-18 10:00:00');
        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('active')->addIngredient(new IngredientBuilder()->isEgg())->build(),
            new ShoppingListBuilder()->withId('empty')->build(),
            new ShoppingListBuilder()->withId('paused')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(Status::PAUSED)->build(),
            new ShoppingListBuilder()->withId('completed')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(Status::COMPLETED)->build(),
            new ShoppingListBuilder()->withId('sent')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(Status::SENT)
                ->withSentAt(new DateTimeImmutable('2026-10-17 08:00:00'))->build(),
        ]);

        $this->handler = new SendShoppingListHandler($this->shoppingListRepository, new FrozenClock($this->now));
    }

    public function testSendsTheActiveListAtTheClockTime(): void
    {
        // When
        ($this->handler)(new SendShoppingListCommand('active'));

        // Then
        $list = $this->shoppingListRepository->findOneById('active');
        $this->assertSame(Status::SENT, $list->getStatus());
        $this->assertSame($this->now, $list->getSentAt());
    }

    public function testRefusesAnEmptyListAndKeepsItActive(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::empty('empty'));

        // When
        try {
            ($this->handler)(new SendShoppingListCommand('empty'));
        } finally {
            $this->assertSame(Status::ACTIVE, $this->shoppingListRepository->findOneById('empty')->getStatus());
        }
    }

    #[DataProvider('notSendableLists')]
    public function testRefusesAListThatIsNeitherActiveNorSent(string $id): void
    {
        // Given
        $status = $this->shoppingListRepository->findOneById($id)->getStatus();

        // Then
        $this->expectExceptionObject(ShoppingListException::notSendable($id));

        // When
        try {
            ($this->handler)(new SendShoppingListCommand($id));
        } finally {
            $this->assertSame($status, $this->shoppingListRepository->findOneById($id)->getStatus());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notSendableLists(): iterable
    {
        yield 'en pause' => ['paused'];
        yield 'terminée' => ['completed'];
    }

    public function testSendingASentListAgainHasNoEffect(): void
    {
        // Given
        $sentAt = $this->shoppingListRepository->findOneById('sent')->getSentAt();

        // When
        ($this->handler)(new SendShoppingListCommand('sent'));

        // Then
        $list = $this->shoppingListRepository->findOneById('sent');
        $this->assertSame([Status::SENT, $sentAt], [$list->getStatus(), $list->getSentAt()]);
    }

    public function testRefusesAnOutdatedVersionAndSendsNothing(): void
    {
        // Given : la liste est à la version 1, le client croit à la 2
        $command = new SendShoppingListCommand('active', expectedVersion: 2);

        // Then
        $this->expectExceptionObject(ShoppingListException::outdated('active'));

        // When
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame(Status::ACTIVE, $this->shoppingListRepository->findOneById('active')->getStatus());
        }
    }

    public function testAcceptsTheCurrentVersion(): void
    {
        // When
        ($this->handler)(new SendShoppingListCommand('active', expectedVersion: 1));

        // Then
        $this->assertSame(Status::SENT, $this->shoppingListRepository->findOneById('active')->getStatus());
    }

    public function testFailsOnAnUnknownList(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('unknown'));

        // When
        ($this->handler)(new SendShoppingListCommand('unknown'));
    }
}

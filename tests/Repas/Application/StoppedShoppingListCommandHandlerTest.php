<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListHandler;
use Repas\Shared\Domain\Event\ShoppingListCompletedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class StoppedShoppingListCommandHandlerTest extends TestCase
{
    private readonly StoppedShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $user = new UserBuilder()->withId('user-id')->build();

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('active-id')->withStatus(Status::ACTIVE)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('pause-id')->withStatus(Status::PAUSED)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('fini-id')->withStatus(Status::COMPLETED)->withOwner($user)->build(),
        ]);

        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new StoppedShoppingListHandler(
            $this->shoppingListRepository,
            $this->eventDispatcher,
        );
    }

    public static function successfullyHandleDataProvider(): array
    {
        return [
            "active-id" => ['active-id', [
                'active-id' => Status::COMPLETED,
                'pause-id' => Status::PAUSED,
                'fini-id' => Status::COMPLETED,
            ]],
            "paused" => ['pause-id', [
                'active-id' => Status::ACTIVE,
                'pause-id' => Status::COMPLETED,
                'fini-id' => Status::COMPLETED,
            ]],
            "fini-id" => ['fini-id', [
                'active-id' => Status::ACTIVE,
                'pause-id' => Status::PAUSED,
                'fini-id' => Status::COMPLETED,
            ]],
        ];
    }

    #[DataProvider('successfullyHandleDataProvider')]
    public function testSuccessfullyHandleActivatedShoppingList(string $shoppingListId, array $expected): void
    {
        // Arrange
        $command = new StoppedShoppingListCommand($shoppingListId);

        // Act
        ($this->handler)($command);

        // Assert
        foreach ($expected as $id => $status) {
            $actual = $this->shoppingListRepository->findOneById($id);
            $this->assertEquals($status, $actual->getStatus());
        }

        // Le contexte `User` en tient la statistique : le handler annonce la fin de la liste et rien d'autre
        $completed = $this->shoppingListRepository->findOneById($shoppingListId);
        $this->assertEquals([new ShoppingListCompletedEvent(
            ownerId: 'user-id',
            shoppingListId: $shoppingListId,
            createdAt: $completed->getCreatedAt(),
            mealCount: 0,
            ingredientCount: 0,
            rowCount: 0,
        )], $this->eventDispatcher->eventDispatched);
    }

    public function testFailedHandleActivatedShoppingListNotFound(): void
    {
        // Arrange
        $command = new StoppedShoppingListCommand('not-founded-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('not-founded-id'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testCompletesASentListAndAnnouncesIt(): void
    {
        // Arrange : terminer reste permis depuis une liste envoyée au drive (ADR 0023)
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('sent-id')->withOwner(new UserBuilder()->withId('user-id')->build())
                ->addIngredient(new IngredientBuilder()->isEgg())->withStatus(Status::SENT)->build()
        );

        // Act
        ($this->handler)(new StoppedShoppingListCommand('sent-id'));

        // Assert
        $this->assertSame(Status::COMPLETED, $this->shoppingListRepository->findOneById('sent-id')->getStatus());
        $event = $this->eventDispatcher->getLastEventDispatched();
        $this->assertInstanceOf(ShoppingListCompletedEvent::class, $event);
        $this->assertSame(['user-id', 'sent-id', 1], [$event->ownerId, $event->shoppingListId, $event->rowCount]);
    }
}

<?php

namespace Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class CreateShoppingListHandlerTest extends TestCase
{
    private readonly CreateShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->clock = new FrozenClock(new DateTimeImmutable());

        $this->handler = new CreateShoppingListHandler(
            $this->shoppingListRepository,
            $this->eventDispatcher,
            $this->clock
        );
    }

    public function testHandleSuccessfullyCreateShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->withId('shopping-list-id')->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new CreateShoppingListCommand("unique_id", $user->getId());

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertEquals([new NewShoppingListCreatedEvent("unique_id", $user->getId())], $this->eventDispatcher->eventDispatched);
        $expected = new ShoppingListBuilder()->withId("unique_id")->withOwner($user)->build();
        $actual = $this->shoppingListRepository->findOneById("unique_id");
        RepasAssert::assertShoppingList($expected, $actual);

        $actualShoppingList = $this->shoppingListRepository->findOneById("shopping-list-id");
        $this->assertEquals(ShoppingListStatus::PAUSED, $actualShoppingList->getStatus());
    }

}

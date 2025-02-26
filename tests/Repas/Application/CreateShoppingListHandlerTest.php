<?php

namespace Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Repas\Application\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CreateShoppingListHandlerTest extends TestCase
{
    private readonly CreateShoppingListHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly EventDispatcherInterface $eventDispatcher;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->clock = new FrozenClock(new DateTimeImmutable());

        $this->handler = new CreateShoppingListHandler(
            $this->userRepository,
            $this->shoppingListRepository,
            $this->eventDispatcher,
            $this->clock
        );
    }

    public function testHandleSuccessfullyCreateShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->withId('shopping-list-id')->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new CreateShoppingListCommand("unique_id", $user->getId());

        // Assert
        $this->eventDispatcher->expects(self::once())->method('dispatch')->with(new NewShoppingListCreatedEvent("unique_id"));

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListBuilder()->withId("unique_id")->withOwner($user)->build();
        $actual = $this->shoppingListRepository->findOneById("unique_id");
        RepasAssert::assertShoppingList($expected, $actual);

        $actualShoppingList = $this->shoppingListRepository->findOneById("shopping-list-id");
        $this->assertEquals(ShoppingListStatus::PAUSED, $actualShoppingList->getStatus());
    }

    public function testHandleFailedCreateShoppingListUnknownUser(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $command = new CreateShoppingListCommand("unique_id", $user->getId());

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->expectExceptionObject(UserException::NotFound($user->getId()));

        // Act
        ($this->handler)($command);
    }
}

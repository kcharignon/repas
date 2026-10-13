<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListHandler;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

class EnsureActiveShoppingListHandlerTest extends TestCase
{
    private UserRepository $userRepository;
    private ShoppingListRepository $shoppingListRepository;
    private SpyEventDispatcher $eventDispatcher;
    private EnsureActiveShoppingListHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new EnsureActiveShoppingListHandler(
            $this->shoppingListRepository,
            new CreateShoppingListHandler(
                $this->userRepository,
                $this->shoppingListRepository,
                $this->eventDispatcher,
                new FrozenClock(new DateTimeImmutable()),
            ),
        );
    }

    public function testCreatesAnActiveListWhenTheUserHasNone(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $user->getId()));

        // Then
        $created = $this->shoppingListRepository->findOneById('new-list');
        $this->assertSame(ShoppingListStatus::ACTIVE, $created->getStatus());
        $this->assertTrue($created->getOwner()->isEqual($user));
        $this->assertCount(1, $this->eventDispatcher->eventDispatched);
    }

    public function testCreatesAnActiveListWhenTheUserOnlyHasPausedOnes(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('paused-list')->withOwner($user)->withStatus(ShoppingListStatus::PAUSED)->build());

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $user->getId()));

        // Then
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('new-list')->getStatus());
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingListRepository->findOneById('paused-list')->getStatus());
    }

    public function testDoesNothingWhenTheUserAlreadyHasAnActiveList(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('active-list')->withOwner($user)->build());

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $user->getId()));

        // Then
        $this->assertSame('active-list', $this->shoppingListRepository->findOneActivateByOwner($user)->getId());
        $this->assertCount(0, $this->eventDispatcher->eventDispatched);
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user));
    }

    public function testFailsForAnUnknownUser(): void
    {
        // Given
        $unknownId = new UserBuilder()->build()->getId();

        // Then
        $this->expectExceptionObject(UserException::NotFound($unknownId));

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $unknownId));
    }
}

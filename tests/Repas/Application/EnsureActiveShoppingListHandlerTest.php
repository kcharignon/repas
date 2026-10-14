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

class EnsureActiveShoppingListHandlerTest extends TestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private SpyEventDispatcher $eventDispatcher;
    private EnsureActiveShoppingListHandler $handler;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new EnsureActiveShoppingListHandler(
            $this->shoppingListRepository,
            new CreateShoppingListHandler(
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

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $user->getId()));

        // Then
        $created = $this->shoppingListRepository->findOneById('new-list');
        $this->assertSame(ShoppingListStatus::ACTIVE, $created->getStatus());
        $this->assertSame($user->getId(), $created->getOwnerId());
        $this->assertCount(1, $this->eventDispatcher->eventDispatched);
    }

    public function testCreatesAnActiveListWhenTheUserOnlyHasPausedOnes(): void
    {
        // Given
        $user = new UserBuilder()->build();
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
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('active-list')->withOwner($user)->build());

        // When
        ($this->handler)(new EnsureActiveShoppingListCommand('new-list', $user->getId()));

        // Then
        $this->assertSame('active-list', $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getId());
        $this->assertCount(0, $this->eventDispatcher->eventDispatched);
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }
}

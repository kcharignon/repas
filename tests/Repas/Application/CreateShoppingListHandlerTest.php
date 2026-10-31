<?php

namespace Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class CreateShoppingListHandlerTest extends TestCase
{
    private readonly CreateShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;
    private readonly ClockInterface $clock;
    private readonly ShoppingListTypeInMemoryRepository $listTypeRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->clock = new FrozenClock(new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE));

        $this->handler = new CreateShoppingListHandler(
            $this->shoppingListRepository,
            $this->eventDispatcher,
            $this->clock,
            $this->listTypeRepository,
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


    public function testDoesNotTouchASentList(): void
    {
        // Arrange : la liste envoyée au drive garde sa place ; la liste active est mise en pause (ADR 0023)
        $user = new UserBuilder()->build();
        $sent = new ShoppingListBuilder()->withOwner($user)->withId('sent-id')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(ShoppingListStatus::SENT)->build();
        $sentAt = $sent->getSentAt();
        $this->shoppingListRepository->save($sent);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('active-id')->build());

        // Act
        ($this->handler)(new CreateShoppingListCommand('new-id', $user->getId()));

        // Assert
        $this->assertSame(ShoppingListStatus::SENT, $this->shoppingListRepository->findOneById('sent-id')->getStatus());
        $this->assertSame($sentAt, $this->shoppingListRepository->findOneById('sent-id')->getSentAt());
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingListRepository->findOneById('active-id')->getStatus());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('new-id')->getStatus());
    }

    public function testAnOwnerWhoKeepsTheMaximumNumberOfListsCannotCreateAnotherAndNothingIsPaused(): void
    {
        // Arrange : une liste active et 999 en pause, plus une terminée qui ne compte pas
        $user = new UserBuilder()->build();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('active-id')->build());
        for ($i = 0; $i < ShoppingList::MAX_PER_OWNER - 1; $i++) {
            $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId("paused-$i")->withStatus(ShoppingListStatus::PAUSED)->build());
        }
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('done-id')->withStatus(ShoppingListStatus::COMPLETED)->build());

        // Assert
        $this->expectExceptionObject(ShoppingListException::limitReached());

        // Act
        try {
            ($this->handler)(new CreateShoppingListCommand('one-too-many', $user->getId()));
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
            $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('active-id')->getStatus());
        }
    }

    public function testAnOwnerBelowTheMaximumStillCreatesTheLastList(): void
    {
        // Arrange : une liste active et 998 en pause, plus dix terminées qui ne comptent pas
        $user = new UserBuilder()->build();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('active-id')->build());
        for ($i = 0; $i < ShoppingList::MAX_PER_OWNER - 2; $i++) {
            $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId("paused-$i")->withStatus(ShoppingListStatus::PAUSED)->build());
        }
        for ($i = 0; $i < 10; $i++) {
            $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId("done-$i")->withStatus(ShoppingListStatus::COMPLETED)->build());
        }

        // Act
        ($this->handler)(new CreateShoppingListCommand('the-last', $user->getId()));

        // Assert
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('the-last')->getStatus());
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingListRepository->findOneById('active-id')->getStatus());
    }

    public function testATypedListIsNamedLikeItsTypeAndLeavesTheMainListAndOtherTypesAlone(): void
    {
        // Given : la liste principale et la liste Marché sont en cours, le type Boucherie n'a pas de liste
        $user = new UserBuilder()->build();
        $butcher = $this->savedType($user->getId(), 'butcher', 'Boucherie');
        $market = $this->savedType($user->getId(), 'market', 'Marché');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('main-id')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('market-id')->withListTypeId($market->getId())->build());

        // When
        ($this->handler)(new CreateShoppingListCommand('butcher-list', $user->getId(), $butcher->getId()));

        // Then
        $created = $this->shoppingListRepository->findOneById('butcher-list');
        $this->assertSame([$butcher->getId(), 'Boucherie', ShoppingListStatus::ACTIVE], [$created->getListTypeId(), $created->getName(), $created->getStatus()]);
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('main-id')->getStatus());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('market-id')->getStatus());
        $this->assertEquals([new NewShoppingListCreatedEvent('butcher-list', $user->getId())], $this->eventDispatcher->eventDispatched);
    }

    public function testATypedListPausesOnlyTheCurrentListOfTheSameType(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $butcher = $this->savedType($user->getId(), 'butcher', 'Boucherie');
        $market = $this->savedType($user->getId(), 'market', 'Marché');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('main-id')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('market-id')->withListTypeId($market->getId())->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('butcher-id')->withListTypeId($butcher->getId())->build());

        // When
        ($this->handler)(new CreateShoppingListCommand('butcher-new', $user->getId(), $butcher->getId()));

        // Then
        $statuses = array_map(fn(string $id): ShoppingListStatus => $this->shoppingListRepository->findOneById($id)->getStatus(), ['main-id', 'market-id', 'butcher-id', 'butcher-new']);
        $this->assertSame([ShoppingListStatus::ACTIVE, ShoppingListStatus::ACTIVE, ShoppingListStatus::PAUSED, ShoppingListStatus::ACTIVE], $statuses);
    }

    public function testTheMainListIgnoresTheTypedListsInProgress(): void
    {
        // Given : seule une liste de type est en cours
        $user = new UserBuilder()->build();
        $butcher = $this->savedType($user->getId(), 'butcher', 'Boucherie');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('butcher-id')->withListTypeId($butcher->getId())->build());

        // When : sans type, la liste principale
        ($this->handler)(new CreateShoppingListCommand('main-new', $user->getId()));

        // Then
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('butcher-id')->getStatus());
        $this->assertNull($this->shoppingListRepository->findOneById('main-new')->getListTypeId());
        $this->assertNull($this->shoppingListRepository->findOneById('main-new')->getName());
    }

    public function testTheTypeOfAnotherUserIsUnknownAndNothingIsWritten(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $theirs = $this->savedType('someone-else', 'theirs', 'Boucherie');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('main-id')->build());

        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        try {
            ($this->handler)(new CreateShoppingListCommand('new-id', $user->getId(), $theirs->getId()));
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
            $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('main-id')->getStatus());
            $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    public function testAnUnknownTypeIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->withId('main-id')->build());

        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        try {
            ($this->handler)(new CreateShoppingListCommand('new-id', $user->getId(), 'unknown-type'));
        } finally {
            $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('main-id')->getStatus());
            $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    private function savedType(string $ownerId, string $id, string $name): ShoppingListType
    {
        $type = ShoppingListType::create($id, $ownerId, $name, new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE));
        $this->listTypeRepository->save($type);

        return $type;
    }
}

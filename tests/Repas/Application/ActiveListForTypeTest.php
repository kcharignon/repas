<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

/**
 * L'ouverture à la demande (ADR 0036 décision 7) : la liste en cours d'un type, ou une liste neuve nommée comme lui, sans toucher aux autres.
 */
class ActiveListForTypeTest extends TestCase
{
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private ShoppingListTypeInMemoryRepository $listTypeRepository;
    private SpyEventDispatcher $eventDispatcher;
    private ActiveListForType $service;
    private string $ownerId;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->ownerId = new UserBuilder()->build()->getId();

        $this->service = new ActiveListForType(
            $this->shoppingListRepository,
            new CreateShoppingListHandler($this->shoppingListRepository, $this->eventDispatcher, new FrozenClock(new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE)), $this->listTypeRepository),
            new ControlledUuidGenerator(['opened-id']),
        );
        $this->listTypeRepository->save(ShoppingListType::create('butcher', $this->ownerId, 'Boucherie', new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE)));
    }

    private function savedList(string $id, ?string $typeId, Status $status = Status::ACTIVE): void
    {
        $builder = new ShoppingListBuilder()->withId($id)->withOwner(new UserBuilder()->withId($this->ownerId))->withListTypeId($typeId);
        if ($status === Status::SENT) {
            $builder->addIngredient(new IngredientBuilder()->isEgg());
        }
        $this->shoppingListRepository->save($builder->withStatus($status)->build());
    }

    private function statusOf(string $id): Status
    {
        return $this->shoppingListRepository->findOneById($id)->getStatus();
    }

    public function testReturnsTheCurrentListOfTheTypeWithoutOpeningAnything(): void
    {
        // Given
        $this->savedList('main-id', null);
        $this->savedList('butcher-id', 'butcher');

        // When
        $result = $this->service->forType($this->ownerId, 'butcher');

        // Then
        $this->assertSame(['butcher-id', false], [$result->shoppingList->getId(), $result->opened]);
        $this->assertCount(2, $this->shoppingListRepository->findByOwner($this->ownerId));
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
    }

    public function testOpensAListNamedLikeTheTypeWithoutTouchingTheOtherLists(): void
    {
        // Given : la liste principale est en cours, une liste d'un autre type est en pause
        $this->savedList('main-id', null);
        $this->savedList('market-paused', 'market', Status::PAUSED);

        // When
        $result = $this->service->forType($this->ownerId, 'butcher');

        // Then
        $this->assertSame(['opened-id', true, 'butcher', 'Boucherie', Status::ACTIVE], [
            $result->shoppingList->getId(), $result->opened, $result->shoppingList->getListTypeId(), $result->shoppingList->getName(), $result->shoppingList->getStatus(),
        ]);
        $this->assertSame(Status::ACTIVE, $this->statusOf('main-id'));
        $this->assertSame(Status::PAUSED, $this->statusOf('market-paused'));
        $this->assertEquals([new NewShoppingListCreatedEvent('opened-id', $this->ownerId)], $this->eventDispatcher->eventDispatched);
    }

    public function testASentListOfTheTypeIsNeverModifiedAndANewListIsOpened(): void
    {
        // Given
        $this->savedList('butcher-sent', 'butcher', Status::SENT);
        $sentAt = $this->shoppingListRepository->findOneById('butcher-sent')->getSentAt();

        // When
        $result = $this->service->forType($this->ownerId, 'butcher');

        // Then
        $this->assertSame(['opened-id', true], [$result->shoppingList->getId(), $result->opened]);
        $sent = $this->shoppingListRepository->findOneById('butcher-sent');
        $this->assertSame(Status::SENT, $sent->getStatus());
        $this->assertSame($sentAt, $sent->getSentAt());
    }

    public function testTheMainListIsNeverOpenedSilently(): void
    {
        // Given : seule une liste de type est en cours
        $this->savedList('butcher-id', 'butcher');

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        try {
            $this->service->forType($this->ownerId, null);
        } finally {
            $this->assertCount(1, $this->shoppingListRepository->findByOwner($this->ownerId));
        }
    }

    public function testReturnsTheMainListWhenItIsInProgress(): void
    {
        // Given
        $this->savedList('butcher-id', 'butcher');
        $this->savedList('main-id', null);

        // When
        $result = $this->service->forType($this->ownerId, null);

        // Then
        $this->assertSame(['main-id', false], [$result->shoppingList->getId(), $result->opened]);
    }

    public function testTheTypeOfAnotherUserIsNotFoundAndNothingIsOpened(): void
    {
        // Given
        $this->listTypeRepository->save(ShoppingListType::create('theirs', 'someone-else', 'Boucherie', new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE)));

        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        try {
            $this->service->forType($this->ownerId, 'theirs');
        } finally {
            $this->assertSame([], $this->shoppingListRepository->findByOwner($this->ownerId)->toArray());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testTheListLimitStillApplies(): void
    {
        // Given : 1000 listes en pause
        for ($i = 0; $i < ShoppingList::MAX_PER_OWNER; $i++) {
            $this->savedList("paused-$i", null, Status::PAUSED);
        }

        // Then
        $this->expectExceptionObject(ShoppingListException::limitReached());

        // When
        $this->service->forType($this->ownerId, 'butcher');
    }
}

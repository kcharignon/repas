<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class ActivatedShoppingListCommandHandlerTest extends TestCase
{
    private readonly ActivatedShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $user = new UserBuilder()->build();



        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('active-id')->withStatus(Status::ACTIVE)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('pause-id')->withStatus(Status::PAUSED)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('fini-id')->withStatus(Status::COMPLETED)->withOwner($user)->build(),
        ]);

        $this->handler = new ActivatedShoppingListHandler(
            $this->shoppingListRepository,
        );
    }

    public static function successfullyHandleDataProvider(): array
    {
        return [
            "active-id" => ['active-id', [
                'active-id' => Status::ACTIVE,
                'pause-id' => Status::PAUSED,
                'fini-id' => Status::COMPLETED,
            ]],
            "paused" => ['pause-id', [
                'active-id' => Status::PAUSED,
                'pause-id' => Status::ACTIVE,
                'fini-id' => Status::COMPLETED,
            ]],
            "fini-id" => ['fini-id', [
                'active-id' => Status::PAUSED,
                'pause-id' => Status::PAUSED,
                'fini-id' => Status::ACTIVE,
            ]],
        ];
    }

    #[DataProvider('successfullyHandleDataProvider')]
    public function testSuccessfullyHandleActivatedShoppingList(string $shoppingListId, array $expected): void
    {
        // Arrange
        $command = new ActivatedShoppingListCommand($shoppingListId);

        // Act
        ($this->handler)($command);

        // Assert
        foreach ($expected as $id => $status) {
            $actual = $this->shoppingListRepository->findOneById($id);
            $this->assertEquals($status, $actual->getStatus());
        }
    }

    public function testFailedHandleActivatedShoppingListNotFound(): void
    {
        // Arrange
        $command = new ActivatedShoppingListCommand('not-founded-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('not-founded-id'));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesASentListAndChangesNoStatus(): void
    {
        // Arrange : une liste envoyée au drive n'est plus modifiable (ADR 0023), ni activée ni mise en pause
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('sent-id')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(Status::SENT)->build()
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::sent('sent-id'));

        // Act
        try {
            ($this->handler)(new ActivatedShoppingListCommand('sent-id'));
        } finally {
            $this->assertSame(Status::SENT, $this->shoppingListRepository->findOneById('sent-id')->getStatus());
            $this->assertSame(Status::ACTIVE, $this->shoppingListRepository->findOneById('active-id')->getStatus());
        }
    }

    public function testActivatingATypedListPausesOnlyTheCurrentListOfItsType(): void
    {
        // Given : la liste principale (active-id) et la Marché sont en cours, deux listes Boucherie (une en cours, une en pause)
        $owner = new UserBuilder()->withId($this->shoppingListRepository->findOneById('active-id')->getOwnerId());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('market-id')->withOwner($owner)->withListTypeId('market')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher-active')->withOwner($owner)->withListTypeId('butcher')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher-paused')->withOwner($owner)->withListTypeId('butcher')->withStatus(Status::PAUSED)->build());

        // When
        ($this->handler)(new ActivatedShoppingListCommand('butcher-paused'));

        // Then
        $statuses = array_map(fn(string $id): Status => $this->shoppingListRepository->findOneById($id)->getStatus(), ['butcher-active', 'butcher-paused', 'market-id', 'active-id']);
        $this->assertSame([Status::PAUSED, Status::ACTIVE, Status::ACTIVE, Status::ACTIVE], $statuses);
    }

    public function testActivatingTheMainListLeavesTheTypedListsInProgressAlone(): void
    {
        // Given : la liste principale en cours (active-id), la Boucherie en cours, et une autre principale en pause qu'on active
        $owner = new UserBuilder()->withId($this->shoppingListRepository->findOneById('active-id')->getOwnerId());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher-active')->withOwner($owner)->withListTypeId('butcher')->build());

        // When
        ($this->handler)(new ActivatedShoppingListCommand('pause-id'));

        // Then
        $this->assertSame(Status::PAUSED, $this->shoppingListRepository->findOneById('active-id')->getStatus());
        $this->assertSame(Status::ACTIVE, $this->shoppingListRepository->findOneById('pause-id')->getStatus());
        $this->assertSame(Status::ACTIVE, $this->shoppingListRepository->findOneById('butcher-active')->getStatus());
    }
}

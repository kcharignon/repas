<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Application\StoppedShoppingList\StoppedShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class StoppedShoppingListCommandHandlerTest extends TestCase
{
    private readonly StoppedShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $user = new UserBuilder()->build();



        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('active-id')->withStatus(Status::ACTIVE)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('pause-id')->withStatus(Status::PAUSED)->withOwner($user)->build(),
            new ShoppingListBuilder()->withId('fini-id')->withStatus(Status::COMPLETED)->withOwner($user)->build(),
        ]);

        $this->handler = new StoppedShoppingListHandler(
            $this->shoppingListRepository,
        );
    }

    public function successfullyHandleDataProvider(): array
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

    /**
     * @dataProvider successfullyHandleDataProvider
     */
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
    }

    public function testFailedHandleActivatedShoppingListNotFound(): void
    {
        // Arrange
        $command = new StoppedShoppingListCommand('not-founded-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('not-founded-id'));

        // Act
        ($this->handler)($command);
    }
}

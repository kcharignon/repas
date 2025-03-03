<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Application\UpdateShoppingList\UpdateShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class UpdateShoppingListHandlerTest extends TestCase
{
    private readonly UpdateShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('no-name')->build(),
            new ShoppingListBuilder()->withId('named')->withName('EVG')->build(),
        ]);

        $this->handler = new UpdateShoppingListHandler($this->shoppingListRepository);
    }

    public static function updateShoppingListHandlerSuccessDataProvider(): array
    {
        return [
            'unnamed => named' => ['no-name', 'courses'],
            'named => other named' => ['named', 'courses'],
            'named => unnamed' => ['named', null],
            'unnamed => unnamed' => ['no-name', null],
        ];
    }

    #[DataProvider('updateShoppingListHandlerSuccessDataProvider')]
    public function testUpdateShoppingListHandlerSuccess(string $shoppingListId, ?string $newName): void
    {
        // Arrange
        $command = new UpdateShoppingListCommand($shoppingListId, $newName);

        // Act
        ($this->handler)($command);

        // Assert
        $actualName = $this->shoppingListRepository->findOneById($shoppingListId)->getName();
        $this->assertSame($newName, $actualName);
    }

    public function testUpdateShoppingListHandlerFailedShoppingListNotFound(): void
    {
        // Arrange
        $command = new UpdateShoppingListCommand("not-found", "nouveau nom");

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('not-found'));

        // Act
        ($this->handler)($command);
    }
}

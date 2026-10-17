<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
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

    public function testRefusesAnOutdatedVersionAndKeepsTheName(): void
    {
        // Arrange : la liste est à la version 1, le client croit à la 2
        $command = new UpdateShoppingListCommand('named', 'courses', expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated('named'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame('EVG', $this->shoppingListRepository->findOneById('named')->getName());
        }
    }

    public function testRefusesASentListAndKeepsTheName(): void
    {
        // Arrange : une liste envoyée au drive n'est plus modifiable (ADR 0023)
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('sent')->withName('EVG')->addIngredient(new IngredientBuilder()->isEgg())->withStatus(ShoppingListStatus::SENT)->build()
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::sent('sent'));

        // Act
        try {
            ($this->handler)(new UpdateShoppingListCommand('sent', 'courses'));
        } finally {
            $this->assertSame('EVG', $this->shoppingListRepository->findOneById('sent')->getName());
        }
    }
}

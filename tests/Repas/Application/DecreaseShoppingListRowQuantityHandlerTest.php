<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity\DecreaseShoppingListRowQuantityCommand;
use Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity\DecreaseShoppingListRowQuantityHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;

class DecreaseShoppingListRowQuantityHandlerTest extends TestCase
{
    private DecreaseShoppingListRowQuantityHandler $handler;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private ShoppingListRowInMemoryRepository $rowRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->rowRepository = new ShoppingListRowInMemoryRepository();
        $this->handler = new DecreaseShoppingListRowQuantityHandler($this->rowRepository, $this->shoppingListRepository);
    }

    public function testRemovesOneFromTheRow(): void
    {
        // Given : 3 plaquettes
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());
        $list->addIngredient($butter->getSlug(), $list->findRow($butter->getSlug())?->getUnitSlug() ?? '', 2);
        $list->addRow($butter->getSlug(), $list->findRow($butter->getSlug())?->getUnitSlug() ?? '', 2);

        // When
        ($this->handler)(new DecreaseShoppingListRowQuantityCommand($this->rowIdOf($list, $butter->getSlug())));

        // Then
        $this->assertSame(2.0, $list->findRow($butter->getSlug())?->getQuantity());
        $this->assertSame(2.0, array_values($list->getIngredients()->toArray())[0]->getQuantity());
    }

    public function testTheLastOneRemovesTheRowAndTheManualAddition(): void
    {
        // Given
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());

        // When
        ($this->handler)(new DecreaseShoppingListRowQuantityCommand($this->rowIdOf($list, $butter->getSlug())));

        // Then
        $this->assertNull($list->findRow($butter->getSlug()));
        $this->assertCount(0, $list->getIngredients());
    }

    public function testAFractionReadAsOneDisappearsInOneStep(): void
    {
        // Given : 0,4 plaquette, lue « 1 » : un « − » suffit
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());
        $list->subtractRow($butter->getSlug(), 0.6);

        // When
        ($this->handler)(new DecreaseShoppingListRowQuantityCommand($this->rowIdOf($list, $butter->getSlug())));

        // Then
        $this->assertNull($list->findRow($butter->getSlug()));
    }

    public function testRefusesAStaleVersionAndWritesNothing(): void
    {
        // Given
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->withVersion(3)->addIngredient($butter)->build());
        $rowId = $this->rowIdOf($list, $butter->getSlug());

        // Then
        $this->expectExceptionObject(ShoppingListException::outdated($list->getId()));

        // When
        try {
            ($this->handler)(new DecreaseShoppingListRowQuantityCommand($rowId, 2));
        } finally {
            $this->assertSame(1.0, $list->findRow($butter->getSlug())?->getQuantity());
        }
    }

    public function testRefusesARowOfASentListAndWritesNothing(): void
    {
        // Given
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->withStatus(ShoppingListStatus::SENT)->build());
        $rowId = $this->rowIdOf($list, $butter->getSlug());

        // Then
        $this->expectExceptionObject(ShoppingListException::sent($list->getId()));

        // When
        try {
            ($this->handler)(new DecreaseShoppingListRowQuantityCommand($rowId));
        } finally {
            $this->assertSame(1.0, $list->findRow($butter->getSlug())?->getQuantity());
        }
    }

    public function testRefusesAnUnknownRow(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListRowException::notFound('unknown-row'));

        // When
        ($this->handler)(new DecreaseShoppingListRowQuantityCommand('unknown-row'));
    }

    private function savedList(ShoppingList $list): ShoppingList
    {
        $this->shoppingListRepository->save($list);
        foreach ($list->getRows() as $row) {
            $this->rowRepository->save($row);
        }

        return $list;
    }

    private function rowIdOf(ShoppingList $list, string $ingredientSlug): string
    {
        return $list->findRow($ingredientSlug)?->getId() ?? $this->fail('La ligne manque');
    }
}

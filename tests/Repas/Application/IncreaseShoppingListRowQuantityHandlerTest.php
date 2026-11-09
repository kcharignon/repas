<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\IncreaseShoppingListRowQuantity\IncreaseShoppingListRowQuantityCommand;
use Repas\Repas\Application\Command\IncreaseShoppingListRowQuantity\IncreaseShoppingListRowQuantityHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;

class IncreaseShoppingListRowQuantityHandlerTest extends TestCase
{
    private IncreaseShoppingListRowQuantityHandler $handler;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private ShoppingListRowInMemoryRepository $rowRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->rowRepository = new ShoppingListRowInMemoryRepository();
        $this->handler = new IncreaseShoppingListRowQuantityHandler($this->rowRepository, $this->shoppingListRepository);
    }

    public function testAddsOneToTheRowAndToTheManualAddition(): void
    {
        // Given
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());
        $rowId = $this->rowIdOf($list, $butter->getSlug());

        // When
        ($this->handler)(new IncreaseShoppingListRowQuantityCommand($rowId));

        // Then
        $this->assertSame(2.0, $list->findRow($butter->getSlug())?->getQuantity());
        $this->assertSame(2.0, array_values($list->getIngredients()->toArray())[0]->getQuantity());
    }

    public function testKeepsTheExactQuantityOfARowThatWasReadRoundedUp(): void
    {
        // Given : 0,4 plaquette, lue « 1 » ; le « + » de l'écran donne 1,4, lu « 2 »
        $butter = new IngredientBuilder()->isButter()->build();
        $list = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());
        $list->subtractRow($butter->getSlug(), 0.6);
        $rowId = $this->rowIdOf($list, $butter->getSlug());

        // When
        ($this->handler)(new IncreaseShoppingListRowQuantityCommand($rowId));

        // Then
        $this->assertEqualsWithDelta(1.4, $list->findRow($butter->getSlug())?->getQuantity(), 1e-9);
    }

    public function testChangesTheListThatCarriesTheRowAndNoOther(): void
    {
        // Given : deux listes qui portent le même produit
        $butter = new IngredientBuilder()->isButter()->build();
        $first = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());
        $second = $this->savedList(new ShoppingListBuilder()->addIngredient($butter)->build());

        // When
        ($this->handler)(new IncreaseShoppingListRowQuantityCommand($this->rowIdOf($second, $butter->getSlug())));

        // Then
        $this->assertSame(1.0, $first->findRow($butter->getSlug())?->getQuantity());
        $this->assertSame(2.0, $second->findRow($butter->getSlug())?->getQuantity());
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
            ($this->handler)(new IncreaseShoppingListRowQuantityCommand($rowId, 2));
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
            ($this->handler)(new IncreaseShoppingListRowQuantityCommand($rowId));
        } finally {
            $this->assertSame(1.0, $list->findRow($butter->getSlug())?->getQuantity());
        }
    }

    public function testRefusesAnUnknownRow(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListRowException::notFound('unknown-row'));

        // When
        ($this->handler)(new IncreaseShoppingListRowQuantityCommand('unknown-row'));
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

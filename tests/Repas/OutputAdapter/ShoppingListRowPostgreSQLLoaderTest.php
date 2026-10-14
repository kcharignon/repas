<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\ShoppingListRowLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

class ShoppingListRowPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListRowLoader $rowLoader;
    private ShoppingListRowRepository $rowRepository;
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rowLoader = static::getContainer()->get(ShoppingListRowLoader::class);
        $this->rowRepository = static::getContainer()->get(ShoppingListRowRepository::class);
        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testGetReturnsTheRowWithItsIngredientAndUnit(): void
    {
        // Given
        $rowId = $this->savedRowOfPasta(quantity: 499);

        // When
        $result = $this->rowLoader->get($rowId);

        // Then
        $this->assertEquals(new ShoppingListRowDTO($rowId, 'pate', 500.0, 'g', false), $result);
    }

    public function testGetReflectsATickedRow(): void
    {
        // Given
        $rowId = $this->savedRowOfPasta(quantity: 0);
        $row = $this->rowRepository->findOneById($rowId);
        $row->tick();
        $this->rowRepository->save($row);

        // When
        $result = $this->rowLoader->get($rowId);

        // Then
        $this->assertTrue($result->checked);
    }

    public function testGetFailsWhenTheRowDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListRowException::notFound('unknown-row'));

        // When
        $this->rowLoader->get('unknown-row');
    }

    private function savedRowOfPasta(float $quantity): string
    {
        $owner = new UserBuilder()->build();
        $this->persister->saveUser($owner);
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($pasta)->build();
        $shoppingList->addRow($pasta->getSlug(), $pasta->getDefaultPurchaseUnit()->getSlug(), $quantity);
        $this->shoppingListRepository->save($shoppingList);

        return $shoppingList->getRows()->reset()->getId();
    }
}

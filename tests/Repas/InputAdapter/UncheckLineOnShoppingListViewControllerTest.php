<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingListRow\GetShoppingListRowQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Tests\Helper\Builder\DTO\ShoppingListRowDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class UncheckLineOnShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testUnchecksTheLineThenReturnsTheRowFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->addIngredient($pasta)->build();
        $shoppingList->getRows()->reset()->tick();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $rowId = $shoppingList->getRows()->reset()->getId();
        $this->queryBus->willReturn(GetShoppingListRowQuery::class, new ShoppingListRowDTOBuilder()->withId($rowId)->withIngredientName('pâtes')->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/line/' . $rowId . '/uncheck');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse(static::getContainer()->get(ShoppingListRowRepository::class)->findOneById($rowId)->isChecked());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListRowQuery::class, $query);
        $this->assertSame($rowId, $query->rowId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#shopping_list_row_' . $rowId, $view['selector']);
        $this->assertStringContainsString("/shopping-list/line/$rowId/tick", $view['html']);
        $this->assertStringNotContainsString('fa-check-square', $view['html']);
    }
}

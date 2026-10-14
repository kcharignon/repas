<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\IngredientDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveIngredientToActiveShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testRemovesTheIngredientThenReturnsTheRowFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($pasta)->build());
        $this->queryBus->willReturn(GetIngredientQuery::class, new IngredientDTOBuilder()->withSlug($pasta->getSlug())->withName('pâtes')->build());
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/active/ingredient/' . $pasta->getSlug() . '/remove');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($shoppingListRepository->findOneActivateByOwner($user->getId())->getIngredientQuantity($pasta));
        $query = $this->queryBus->getAskedQuery(GetActiveShoppingListSummaryQuery::class);
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#ingredient-row-' . $pasta->getSlug(), $view['selector']);
        $this->assertStringNotContainsString('disabled', $view['html']);
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RenamedShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testRenamesTheListThenReturnsTheHeaderFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetShoppingListQuery::class, new ShoppingListDetailDTOBuilder()->withId($shoppingList->getId())->withName('Zzz nouveau nom')->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/' . $shoppingList->getId() . '/rename?newName=' . urlencode('Zzz nouveau nom'));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $saved = static::getContainer()->get(ShoppingListRepository::class)->findOneById($shoppingList->getId());
        $this->assertSame('Zzz nouveau nom', $saved->getName());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListQuery::class, $query);
        $this->assertSame($shoppingList->getId(), $query->shoppingListId);
        $body = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('success', $body['status']);
        $this->assertSame('#shopping-list-card-header', $body['views'][0]['selector']);
        $this->assertStringContainsString('Zzz nouveau nom', $body['views'][0]['html']);
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $shoppingList = $this->savedShoppingList($this->savedUser());
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('POST', '/shopping-list/' . $shoppingList->getId() . '/rename?newName=Zzz');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

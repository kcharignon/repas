<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class PausedShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testChangesTheStatusThenReturnsTheListFragmentFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $this->queryBus->willReturn(ListShoppingListsQuery::class, [
            new ShoppingListDTOBuilder()->withId($shoppingList->getId())->withDisplayName('Zzz liste affichée')->withStatus(ShoppingListStatus::PAUSED)->build(),
        ]);
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/' . $shoppingList->getId() . '/paused');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $saved = static::getContainer()->get(ShoppingListRepository::class)->findOneById($shoppingList->getId());
        $this->assertSame(ShoppingListStatus::PAUSED, $saved->getStatus());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListShoppingListsQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#shopping-list-list', $view['selector']);
        $this->assertStringContainsString('Zzz liste affichée', $view['html']);
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $shoppingList = $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('POST', '/shopping-list/' . $shoppingList->getId() . '/paused');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
        $saved = static::getContainer()->get(ShoppingListRepository::class)->findOneById($shoppingList->getId());
        $this->assertSame(ShoppingListStatus::ACTIVE, $saved->getStatus());
    }
}

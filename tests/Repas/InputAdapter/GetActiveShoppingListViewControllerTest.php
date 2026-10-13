<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetCurrentShoppingList\GetCurrentShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetActiveShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testShowsTheCurrentListOfTheUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentShoppingListQuery::class, new ShoppingListDetailDTOBuilder()->withName('Ma liste en cours')->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/shopping-list/active');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetCurrentShoppingListQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame(1, $this->queryBus->countAskedQueries());
        $this->assertSame('Ma liste en cours', trim($crawler->filter('#card-header-name')->text()));
    }

    public function testCreatesAnActiveListThenShowsItWhenTheUserHasNone(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentShoppingListQuery::class, null);
        $this->queryBus->willReturn(GetShoppingListQuery::class, new ShoppingListDetailDTOBuilder()->withName('Liste neuve')->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/shopping-list/active');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $created = static::getContainer()->get(ShoppingListRepository::class)->findOneActivateByOwner($user);
        $this->assertSame(ShoppingListStatus::ACTIVE, $created->getStatus());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListQuery::class, $query);
        $this->assertSame($created->getId(), $query->shoppingListId);
        $this->assertSame('Liste neuve', trim($crawler->filter('#card-header-name')->text()));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/shopping-list/active');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

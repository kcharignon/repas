<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetAllShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testPageListsTheShoppingListsOfTheCurrentUserFromTheDTOs(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListShoppingListsQuery::class, [
            new ShoppingListDTOBuilder()->withId('list-active')->withDisplayName('Courses du week-end')->withStatus(ShoppingListStatus::ACTIVE)->build(),
            new ShoppingListDTOBuilder()->withId('list-paused')->withDisplayName('12 octobre 2026')->withStatus(ShoppingListStatus::PAUSED)->build(),
            new ShoppingListDTOBuilder()->withId('list-completed')->withDisplayName('20 janvier 2025')->withStatus(ShoppingListStatus::COMPLETED)->build(),
        ]);
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/shopping-list');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListShoppingListsQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame(
            ['Courses du week-end Active', '12 octobre 2026 En pause', '20 janvier 2025 Terminée'],
            array_map(static fn(string $text): string => trim(preg_replace('/\s+/', ' ', $text)), $crawler->filter('li.shopping-list-item a')->extract(['_text'])),
        );
        $this->assertSame('/shopping-list/list-active', $crawler->filter('#shopping-list-list-active a')->attr('href'));
    }

    public function testEachListOffersOnlyTheActionsOfItsStatus(): void
    {
        // Given
        $this->queryBus->willReturn(ListShoppingListsQuery::class, [
            new ShoppingListDTOBuilder()->withId('list-active')->withStatus(ShoppingListStatus::ACTIVE)->build(),
            new ShoppingListDTOBuilder()->withId('list-paused')->withStatus(ShoppingListStatus::PAUSED)->build(),
            new ShoppingListDTOBuilder()->withId('list-completed')->withStatus(ShoppingListStatus::COMPLETED)->build(),
        ]);
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/shopping-list');

        // Then
        $actionsOf = static fn(string $id): array => array_map(
            static fn(string $url): string => basename($url),
            $crawler->filter("#shopping-list-$id button[data-url]")->extract(['data-url']),
        );
        $this->assertSame(['paused', 'stopped', 'list-active'], $actionsOf('list-active'));
        $this->assertSame(['activated', 'stopped', 'list-paused'], $actionsOf('list-paused'));
        $this->assertSame(['activated', 'list-completed'], $actionsOf('list-completed'));
        $this->assertCount(1, $crawler->filter('#shopping-list-list-completed.list-group-item-dark'));
        $this->assertCount(0, $crawler->filter('#shopping-list-list-active.list-group-item-dark'));
    }

    public function testPageIsStillDisplayedWithoutAnyList(): void
    {
        // Given
        $this->queryBus->willReturn(ListShoppingListsQuery::class, []);
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/shopping-list');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $crawler->filter('form[action="/shopping-list/create"]'));
        $this->assertCount(0, $crawler->filter('li.shopping-list-item'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/shopping-list');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

<?php

namespace Repas\Tests\Repas\Application\Query\ListOpenShoppingLists;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListOpenShoppingLists\ListOpenShoppingListsQuery;
use Repas\Repas\Application\Query\ListOpenShoppingLists\ListOpenShoppingListsQueryHandler;
use Repas\Repas\Application\Query\ListOpenShoppingLists\OpenShoppingListDTO;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class ListOpenShoppingListsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $lists = [
            new OpenShoppingListDTO('list-1', '12 octobre 2026', null, 3, 2, 1),
            new OpenShoppingListDTO('list-2', 'Boucherie', 'type-1', 0, 0, 2),
        ];
        $handler = new ListOpenShoppingListsQueryHandler(new ShoppingListInMemoryLoader(openShoppingLists: $lists));

        // When
        $result = $handler(new ListOpenShoppingListsQuery('owner-1'));

        // Then
        $this->assertSame($lists, $result);
    }

    public function testForwardsTheOwnerToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader();
        $handler = new ListOpenShoppingListsQueryHandler($loader);

        // When
        $handler(new ListOpenShoppingListsQuery('owner-1'));

        // Then
        $this->assertSame(['owner-1'], $loader->listOpenByOwnerCalls);
    }
}

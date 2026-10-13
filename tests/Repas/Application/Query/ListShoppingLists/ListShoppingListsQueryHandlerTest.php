<?php

namespace Repas\Tests\Repas\Application\Query\ListShoppingLists;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class ListShoppingListsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $shoppingLists = [
            new ShoppingListDTOBuilder()->withId('list-1')->build(),
            new ShoppingListDTOBuilder()->withId('list-2')->build(),
        ];
        $handler = new ListShoppingListsQueryHandler(new ShoppingListInMemoryLoader($shoppingLists));

        // When
        $result = $handler(new ListShoppingListsQuery('owner-1'));

        // Then
        $this->assertSame($shoppingLists, $result);
    }

    public function testForwardsTheOwnerToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader();
        $handler = new ListShoppingListsQueryHandler($loader);

        // When
        $handler(new ListShoppingListsQuery('owner-1'));

        // Then
        $this->assertSame(['owner-1'], $loader->listByOwnerCalls);
    }
}

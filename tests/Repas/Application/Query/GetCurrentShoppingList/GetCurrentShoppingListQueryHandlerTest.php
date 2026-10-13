<?php

namespace Repas\Tests\Repas\Application\Query\GetCurrentShoppingList;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetCurrentShoppingList\GetCurrentShoppingListQuery;
use Repas\Repas\Application\Query\GetCurrentShoppingList\GetCurrentShoppingListQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class GetCurrentShoppingListQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $detail = new ShoppingListDetailDTOBuilder()->withId('list-1')->build();
        $handler = new GetCurrentShoppingListQueryHandler(new ShoppingListInMemoryLoader(detail: $detail));

        // When
        $result = $handler(new GetCurrentShoppingListQuery('owner-1'));

        // Then
        $this->assertSame($detail, $result);
    }

    public function testReturnsNullWhenTheUserHasNoList(): void
    {
        // Given
        $handler = new GetCurrentShoppingListQueryHandler(new ShoppingListInMemoryLoader());

        // When
        $result = $handler(new GetCurrentShoppingListQuery('owner-1'));

        // Then
        $this->assertNull($result);
    }

    public function testForwardsTheOwnerToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader();
        $handler = new GetCurrentShoppingListQueryHandler($loader);

        // When
        $handler(new GetCurrentShoppingListQuery('owner-1'));

        // Then
        $this->assertSame(['owner-1'], $loader->getCurrentCalls);
    }
}

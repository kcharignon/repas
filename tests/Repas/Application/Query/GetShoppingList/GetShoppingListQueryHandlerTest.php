<?php

namespace Repas\Tests\Repas\Application\Query\GetShoppingList;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class GetShoppingListQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $detail = new ShoppingListDetailDTOBuilder()->withId('list-1')->build();
        $handler = new GetShoppingListQueryHandler(new ShoppingListInMemoryLoader(detail: $detail));

        // When
        $result = $handler(new GetShoppingListQuery('list-1'));

        // Then
        $this->assertSame($detail, $result);
    }

    public function testForwardsTheIdentifierToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader(detail: new ShoppingListDetailDTOBuilder()->build());
        $handler = new GetShoppingListQueryHandler($loader);

        // When
        $handler(new GetShoppingListQuery('list-1'));

        // Then
        $this->assertSame(['list-1'], $loader->getCalls);
    }
}

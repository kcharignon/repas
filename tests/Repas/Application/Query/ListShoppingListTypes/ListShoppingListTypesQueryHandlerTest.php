<?php

namespace Repas\Tests\Repas\Application\Query\ListShoppingListTypes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListShoppingListTypes\ListShoppingListTypesQuery;
use Repas\Repas\Application\Query\ListShoppingListTypes\ListShoppingListTypesQueryHandler;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListTypeInMemoryLoader;

class ListShoppingListTypesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheUser(): void
    {
        // Given
        $types = [new ShoppingListTypeSummaryDTO('type-1', 'Boucherie', ['viandes'], 2), new ShoppingListTypeSummaryDTO('type-2', 'Marché', [], 0)];
        $loader = new ShoppingListTypeInMemoryLoader(summaries: $types);
        $handler = new ListShoppingListTypesQueryHandler($loader);

        // When
        $result = $handler(new ListShoppingListTypesQuery('user-1'));

        // Then
        $this->assertSame($types, $result);
        $this->assertSame(['user-1'], $loader->listCalls);
    }
}

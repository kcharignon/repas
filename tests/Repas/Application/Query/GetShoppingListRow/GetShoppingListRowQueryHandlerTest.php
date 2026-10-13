<?php

namespace Repas\Tests\Repas\Application\Query\GetShoppingListRow;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetShoppingListRow\GetShoppingListRowQuery;
use Repas\Repas\Application\Query\GetShoppingListRow\GetShoppingListRowQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ShoppingListRowDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListRowInMemoryLoader;

class GetShoppingListRowQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $row = new ShoppingListRowDTOBuilder()->withId('row-1')->build();
        $handler = new GetShoppingListRowQueryHandler(new ShoppingListRowInMemoryLoader($row));

        // When
        $result = $handler(new GetShoppingListRowQuery('row-1'));

        // Then
        $this->assertSame($row, $result);
    }

    public function testForwardsTheRowToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListRowInMemoryLoader(new ShoppingListRowDTOBuilder()->build());
        $handler = new GetShoppingListRowQueryHandler($loader);

        // When
        $handler(new GetShoppingListRowQuery('row-1'));

        // Then
        $this->assertSame(['row-1'], $loader->getCalls);
    }
}

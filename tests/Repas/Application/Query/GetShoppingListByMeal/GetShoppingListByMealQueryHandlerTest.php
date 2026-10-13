<?php

namespace Repas\Tests\Repas\Application\Query\GetShoppingListByMeal;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetShoppingListByMeal\GetShoppingListByMealQuery;
use Repas\Repas\Application\Query\GetShoppingListByMeal\GetShoppingListByMealQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class GetShoppingListByMealQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $detail = new ShoppingListDetailDTOBuilder()->withId('list-1')->build();
        $handler = new GetShoppingListByMealQueryHandler(new ShoppingListInMemoryLoader(detail: $detail));

        // When
        $result = $handler(new GetShoppingListByMealQuery('meal-1'));

        // Then
        $this->assertSame($detail, $result);
    }

    public function testForwardsTheMealToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader(detail: new ShoppingListDetailDTOBuilder()->build());
        $handler = new GetShoppingListByMealQueryHandler($loader);

        // When
        $handler(new GetShoppingListByMealQuery('meal-1'));

        // Then
        $this->assertSame(['meal-1'], $loader->getByMealIdCalls);
    }
}

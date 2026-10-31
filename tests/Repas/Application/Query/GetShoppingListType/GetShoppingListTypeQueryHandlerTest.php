<?php

namespace Repas\Tests\Repas\Application\Query\GetShoppingListType;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetShoppingListType\GetShoppingListTypeQuery;
use Repas\Repas\Application\Query\GetShoppingListType\GetShoppingListTypeQueryHandler;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeIngredientDTO;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListTypeInMemoryLoader;

class GetShoppingListTypeQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheTypeAndTheUser(): void
    {
        // Given
        $type = new ShoppingListTypeDTO(
            'type-1',
            'Boucherie',
            [new ShoppingListTypeDepartmentDTO('viandes', 'Viandes')],
            [new ShoppingListTypeIngredientDTO('merguez', 'Merguez', 'charcuterie', 'Charcuterie')],
        );
        $loader = new ShoppingListTypeInMemoryLoader(detail: $type);
        $handler = new GetShoppingListTypeQueryHandler($loader);

        // When
        $result = $handler(new GetShoppingListTypeQuery('type-1', 'user-1'));

        // Then
        $this->assertSame($type, $result);
        $this->assertSame([['type-1', 'user-1']], $loader->getCalls);
    }
}

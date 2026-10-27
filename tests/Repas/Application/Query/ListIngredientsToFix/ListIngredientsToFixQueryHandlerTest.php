<?php

namespace Repas\Tests\Repas\Application\Query\ListIngredientsToFix;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQuery;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientToFixInMemoryLoader;

class ListIngredientsToFixQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $toFix = new IngredientsToFixDTO(
            [new IngredientToFixDTO('biscotte', 'biscotte', 'Épicerie', 'unite', 'unité', 'paquet', 'paquet')],
            1,
        );
        $handler = new ListIngredientsToFixQueryHandler(new IngredientToFixInMemoryLoader($toFix));

        // When
        $result = $handler(new ListIngredientsToFixQuery('user-1'));

        // Then
        $this->assertSame($toFix, $result);
    }

    public function testForwardsTheUserToTheLoader(): void
    {
        // Given
        $loader = new IngredientToFixInMemoryLoader();
        $handler = new ListIngredientsToFixQueryHandler($loader);

        // When
        $handler(new ListIngredientsToFixQuery('user-1'));
        $handler(new ListIngredientsToFixQuery('user-2'));

        // Then
        $this->assertSame(['user-1', 'user-2'], $loader->calls);
    }
}

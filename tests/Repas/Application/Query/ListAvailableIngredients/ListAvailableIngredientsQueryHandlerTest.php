<?php

namespace Repas\Tests\Repas\Application\Query\ListAvailableIngredients;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientInMemoryLoader;

class ListAvailableIngredientsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $ingredients = [new IngredientOptionDTO('farine', 'farine'), new IngredientOptionDTO('oeuf', 'œuf')];
        $handler = new ListAvailableIngredientsQueryHandler(new IngredientInMemoryLoader(availableIngredients: $ingredients));

        // When
        $result = $handler(new ListAvailableIngredientsQuery('user-1'));

        // Then
        $this->assertSame($ingredients, $result);
    }

    public function testForwardsTheUserToTheLoader(): void
    {
        // Given
        $loader = new IngredientInMemoryLoader();
        $handler = new ListAvailableIngredientsQueryHandler($loader);

        // When
        $handler(new ListAvailableIngredientsQuery('user-1'));

        // Then
        $this->assertSame(['user-1'], $loader->listAvailableCalls);
    }
}

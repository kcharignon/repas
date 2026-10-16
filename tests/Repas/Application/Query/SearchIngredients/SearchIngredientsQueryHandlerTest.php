<?php

namespace Repas\Tests\Repas\Application\Query\SearchIngredients;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQuery;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientInMemoryLoader;

class SearchIngredientsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $results = [
            new IngredientSearchResultDTO('tomate', 'tomate', 'g', false, 'fruits-et-legumes', 'Fruits et légumes', 'images/department/fruits.png'),
            new IngredientSearchResultDTO('sauce-tomate', 'sauce tomate', '', true, 'epicerie', 'Épicerie', 'images/department/epicerie.png'),
        ];
        $handler = new SearchIngredientsQueryHandler(new IngredientInMemoryLoader(searchResults: $results));

        // When
        $result = $handler(new SearchIngredientsQuery('tom', 'user-1', false));

        // Then
        $this->assertSame($results, $result);
    }

    public function testForwardsTheSearchAndTheViewerToTheLoader(): void
    {
        // Given
        $loader = new IngredientInMemoryLoader();
        $handler = new SearchIngredientsQueryHandler($loader);

        // When
        $handler(new SearchIngredientsQuery('tom', 'user-1', true));

        // Then
        $this->assertSame([['tom', 'user-1', true]], $loader->searchCalls);
    }
}

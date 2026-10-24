<?php

namespace Repas\Tests\Repas\Application\Query\ListDuplicateIngredients;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientSideDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQuery;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientDuplicateInMemoryLoader;

class ListDuplicateIngredientsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $duplicates = [new DuplicateIngredientDTO(
            new DuplicateIngredientSideDTO('tomate', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo', 12, 3),
            new DuplicateIngredientSideDTO('tomates', 'Tomates', 'fruit-et-legume', 'Fruit & légume', 'kilo', 7, 2),
            1.0,
            'EXACT',
            'tomate',
        )];
        $handler = new ListDuplicateIngredientsQueryHandler(new IngredientDuplicateInMemoryLoader($duplicates));

        // When
        $result = $handler(new ListDuplicateIngredientsQuery('user-1'));

        // Then
        $this->assertSame($duplicates, $result);
    }

    public function testForwardsTheUserToTheLoader(): void
    {
        // Given
        $loader = new IngredientDuplicateInMemoryLoader();
        $handler = new ListDuplicateIngredientsQueryHandler($loader);

        // When
        $handler(new ListDuplicateIngredientsQuery('user-1'));
        $handler(new ListDuplicateIngredientsQuery('user-2'));

        // Then
        $this->assertSame(['user-1', 'user-2'], $loader->calls);
    }
}

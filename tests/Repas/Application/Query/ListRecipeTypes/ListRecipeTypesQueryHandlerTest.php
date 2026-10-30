<?php

namespace Repas\Tests\Repas\Application\Query\ListRecipeTypes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQueryHandler;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeTypeInMemoryLoader;

class ListRecipeTypesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipeTypes = [
            new RecipeTypeCountDTO('entree', 'entrée', 'images/entree.svg', 1, 3),
            new RecipeTypeCountDTO('plat', 'plat', 'images/plat.svg', 2, 0),
        ];
        $loader = new RecipeTypeInMemoryLoader($recipeTypes);
        $handler = new ListRecipeTypesQueryHandler($loader);

        // When
        $result = $handler(new ListRecipeTypesQuery('user-1'));

        // Then
        $this->assertSame($recipeTypes, $result);
        $this->assertSame(1, $loader->listCalls);
        $this->assertSame(['user-1'], $loader->listUserIds);
    }

    public function testReturnsEmptyListWhenLoaderHasNoRecipeType(): void
    {
        // Given
        $handler = new ListRecipeTypesQueryHandler(new RecipeTypeInMemoryLoader());

        // When
        $result = $handler(new ListRecipeTypesQuery('user-1'));

        // Then
        $this->assertSame([], $result);
    }
}

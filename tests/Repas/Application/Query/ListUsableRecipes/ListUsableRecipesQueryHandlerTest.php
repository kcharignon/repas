<?php

namespace Repas\Tests\Repas\Application\Query\ListUsableRecipes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListUsableRecipes\ListUsableRecipesQuery;
use Repas\Repas\Application\Query\ListUsableRecipes\ListUsableRecipesQueryHandler;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipeDTO;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipesDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListUsableRecipesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheParameters(): void
    {
        // Given
        $usable = new UsableRecipesDTO([new UsableRecipeDTO('dough', 'Pâte sablée', 6, 'dessert', 'Dessert', false)], 1, true);
        $loader = new RecipeInMemoryLoader(usable: $usable);
        $handler = new ListUsableRecipesQueryHandler($loader);

        // When
        $result = $handler(new ListUsableRecipesQuery('user-1', 'pie', true, 'pâte'));

        // Then
        $this->assertSame($usable, $result);
        $this->assertSame([['user-1', 'pie', true, 'pâte']], $loader->listUsableCalls);
    }

    public function testABlankRecipeOrSearchIsNotAFilter(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListUsableRecipesQueryHandler($loader);

        // When
        $handler(new ListUsableRecipesQuery('user-1', '  ', false, "  \t"));
        $handler(new ListUsableRecipesQuery('user-1'));

        // Then : ni la recette écrite ni la recherche
        $this->assertSame([['user-1', null, false, null], ['user-1', null, false, null]], $loader->listUsableCalls);
    }
}

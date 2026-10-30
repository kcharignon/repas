<?php

namespace Repas\Tests\Repas\Application\Query\ListFavoriteRecipes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListFavoriteRecipes\ListFavoriteRecipesQuery;
use Repas\Repas\Application\Query\ListFavoriteRecipes\ListFavoriteRecipesQueryHandler;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListFavoriteRecipesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheAuthor(): void
    {
        // Given
        $favorites = [new RecipeSummaryDTO('recipe-1', 'omelette', false), new RecipeSummaryDTO('recipe-2', 'pâtes', true)];
        $loader = new RecipeInMemoryLoader(favorites: $favorites);
        $handler = new ListFavoriteRecipesQueryHandler($loader);

        // When
        $result = $handler(new ListFavoriteRecipesQuery('author-1'));

        // Then
        $this->assertSame($favorites, $result);
        $this->assertSame(['author-1'], $loader->listFavoritesOfAuthorCalls);
    }
}

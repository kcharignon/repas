<?php

namespace Repas\Tests\Repas\Application\Query\ListRecipesByType;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQuery;
use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQueryHandler;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListRecipesByTypeQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipes = new RecipeTypeRecipesDTO(new RecipeTypeDTOBuilder()->build(), [new RecipeSummaryDTO('recipe-1', 'omelette', false)]);
        $handler = new ListRecipesByTypeQueryHandler(new RecipeInMemoryLoader(recipesByType: $recipes));

        // When
        $result = $handler(new ListRecipesByTypeQuery('author-1', 'plat'));

        // Then
        $this->assertSame($recipes, $result);
    }

    public function testForwardsTheAuthorAndTheTypeToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader(recipesByType: new RecipeTypeRecipesDTO(new RecipeTypeDTOBuilder()->build(), []));
        $handler = new ListRecipesByTypeQueryHandler($loader);

        // When
        $handler(new ListRecipesByTypeQuery('author-1', 'plat'));

        // Then
        $this->assertSame([['author-1', 'plat']], $loader->listByAuthorAndTypeCalls);
    }
}

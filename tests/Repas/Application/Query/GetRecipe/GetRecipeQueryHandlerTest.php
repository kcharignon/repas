<?php

namespace Repas\Tests\Repas\Application\Query\GetRecipe;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\GetRecipeQueryHandler;
use Repas\Tests\Helper\Builder\DTO\RecipeDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class GetRecipeQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipe = new RecipeDTOBuilder()->withId('recipe-1')->build();
        $handler = new GetRecipeQueryHandler(new RecipeInMemoryLoader(recipe: $recipe));

        // When
        $result = $handler(new GetRecipeQuery('recipe-1', 'user-1'));

        // Then
        $this->assertSame($recipe, $result);
    }

    public function testForwardsTheIdentifierAndTheReaderToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader(recipe: new RecipeDTOBuilder()->build());
        $handler = new GetRecipeQueryHandler($loader);

        // When
        $handler(new GetRecipeQuery('recipe-1', 'user-1'));

        // Then : le lecteur voyage jusqu'au chargeur, qui décide si une recette secrète lui est visible (ADR 0033)
        $this->assertSame([['recipe-1', 'user-1']], $loader->getCalls);
    }
}

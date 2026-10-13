<?php

namespace Repas\Tests\Repas\Application\Query\GetRecipeType;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetRecipeType\GetRecipeTypeQuery;
use Repas\Repas\Application\Query\GetRecipeType\GetRecipeTypeQueryHandler;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\RecipeTypeInMemoryLoader;

class GetRecipeTypeQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipeType = new RecipeTypeDTOBuilder()->build();
        $handler = new GetRecipeTypeQueryHandler(new RecipeTypeInMemoryLoader(recipeType: $recipeType));

        // When
        $result = $handler(new GetRecipeTypeQuery('slug-1'));

        // Then
        $this->assertSame($recipeType, $result);
    }

    public function testForwardsTheSlugToTheLoader(): void
    {
        // Given
        $loader = new RecipeTypeInMemoryLoader(recipeType: new RecipeTypeDTOBuilder()->build());
        $handler = new GetRecipeTypeQueryHandler($loader);

        // When
        $handler(new GetRecipeTypeQuery('slug-1'));

        // Then
        $this->assertSame(['slug-1'], $loader->getCalls);
    }
}

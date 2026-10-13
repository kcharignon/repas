<?php

namespace Repas\Tests\Repas\Application\Query\ListRecipeTypes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQueryHandler;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\RecipeTypeInMemoryLoader;

class ListRecipeTypesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipeTypes = [
            new RecipeTypeDTOBuilder()->withSlug('entree')->withOrder(1)->build(),
            new RecipeTypeDTOBuilder()->withSlug('plat')->withOrder(2)->build(),
        ];
        $loader = new RecipeTypeInMemoryLoader($recipeTypes);
        $handler = new ListRecipeTypesQueryHandler($loader);

        // When
        $result = $handler(new ListRecipeTypesQuery());

        // Then
        $this->assertSame($recipeTypes, $result);
        $this->assertSame(1, $loader->listCalls);
    }

    public function testReturnsEmptyListWhenLoaderHasNoRecipeType(): void
    {
        // Given
        $handler = new ListRecipeTypesQueryHandler(new RecipeTypeInMemoryLoader());

        // When
        $result = $handler(new ListRecipeTypesQuery());

        // Then
        $this->assertSame([], $result);
    }
}

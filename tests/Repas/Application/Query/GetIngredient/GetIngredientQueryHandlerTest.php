<?php

namespace Repas\Tests\Repas\Application\Query\GetIngredient;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQueryHandler;
use Repas\Tests\Helper\Builder\DTO\IngredientDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\IngredientInMemoryLoader;

class GetIngredientQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $ingredient = new IngredientDTOBuilder()->withSlug('farine')->build();
        $handler = new GetIngredientQueryHandler(new IngredientInMemoryLoader(ingredient: $ingredient));

        // When
        $result = $handler(new GetIngredientQuery('farine', 'user-1'));

        // Then
        $this->assertSame($ingredient, $result);
    }

    public function testForwardsTheIngredientAndTheViewerToTheLoader(): void
    {
        // Given
        $loader = new IngredientInMemoryLoader(ingredient: new IngredientDTOBuilder()->build());
        $handler = new GetIngredientQueryHandler($loader);

        // When
        $handler(new GetIngredientQuery('farine', 'user-1'));

        // Then
        $this->assertSame([['farine', 'user-1']], $loader->getCalls);
    }
}

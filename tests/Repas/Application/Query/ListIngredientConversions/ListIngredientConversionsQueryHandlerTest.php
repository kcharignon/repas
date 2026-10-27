<?php

namespace Repas\Tests\Repas\Application\Query\ListIngredientConversions;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\ListIngredientConversionsQuery;
use Repas\Repas\Application\Query\ListIngredientConversions\ListIngredientConversionsQueryHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\InMemoryLoader\ConversionInMemoryLoader;

class ListIngredientConversionsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheIngredientAndTheViewer(): void
    {
        // Given
        $conversions = new IngredientConversionsDTO([], [], 0, true, 'gramme', 'kilo');
        $loader = new ConversionInMemoryLoader(ingredientConversions: $conversions);
        $handler = new ListIngredientConversionsQueryHandler($loader);

        // When
        $result = $handler(new ListIngredientConversionsQuery('farine', 'user-1'));

        // Then
        $this->assertSame($conversions, $result);
        $this->assertSame([['farine', 'user-1']], $loader->listOfIngredientCalls);
    }

    public function testTheLoadersRefusalOfAnUnknownIngredientIsNotCaught(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('farine'));

        // When
        new ListIngredientConversionsQueryHandler(new ConversionInMemoryLoader())(new ListIngredientConversionsQuery('farine', 'user-1'));
    }
}

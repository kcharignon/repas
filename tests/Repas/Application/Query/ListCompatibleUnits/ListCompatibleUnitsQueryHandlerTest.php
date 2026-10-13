<?php

namespace Repas\Tests\Repas\Application\Query\ListCompatibleUnits;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQuery;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientInMemoryLoader;

class ListCompatibleUnitsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $units = [new CompatibleUnitDTO('gramme', 'Gramme'), new CompatibleUnitDTO('kilo', 'Kilo')];
        $handler = new ListCompatibleUnitsQueryHandler(new IngredientInMemoryLoader(compatibleUnits: $units));

        // When
        $result = $handler(new ListCompatibleUnitsQuery('farine'));

        // Then
        $this->assertSame($units, $result);
    }

    public function testForwardsTheIngredientToTheLoader(): void
    {
        // Given
        $loader = new IngredientInMemoryLoader();
        $handler = new ListCompatibleUnitsQueryHandler($loader);

        // When
        $handler(new ListCompatibleUnitsQuery('farine'));

        // Then
        $this->assertSame(['farine'], $loader->listCompatibleUnitsCalls);
    }
}

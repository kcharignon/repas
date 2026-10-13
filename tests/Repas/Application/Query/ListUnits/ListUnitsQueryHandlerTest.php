<?php

namespace Repas\Tests\Repas\Application\Query\ListUnits;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Application\Query\ListUnits\ListUnitsQueryHandler;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\UnitInMemoryLoader;

class ListUnitsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $units = [
            new UnitDTOBuilder()->withSlug('gramme')->build(),
            new UnitDTOBuilder()->withSlug('kilo')->withName('kilo')->withSymbol('kg')->build(),
        ];
        $loader = new UnitInMemoryLoader($units);
        $handler = new ListUnitsQueryHandler($loader);

        // When
        $result = $handler(new ListUnitsQuery());

        // Then
        $this->assertSame($units, $result);
        $this->assertSame(1, $loader->listCalls);
    }

    public function testReturnsEmptyListWhenLoaderHasNoUnit(): void
    {
        // Given
        $handler = new ListUnitsQueryHandler(new UnitInMemoryLoader());

        // When
        $result = $handler(new ListUnitsQuery());

        // Then
        $this->assertSame([], $result);
    }
}

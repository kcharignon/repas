<?php

namespace Repas\Tests\Repas\Application\Query\GetUnit;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetUnit\GetUnitQuery;
use Repas\Repas\Application\Query\GetUnit\GetUnitQueryHandler;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\UnitInMemoryLoader;

class GetUnitQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $unit = new UnitDTOBuilder()->build();
        $handler = new GetUnitQueryHandler(new UnitInMemoryLoader(unit: $unit));

        // When
        $result = $handler(new GetUnitQuery('slug-1'));

        // Then
        $this->assertSame($unit, $result);
    }

    public function testForwardsTheSlugToTheLoader(): void
    {
        // Given
        $loader = new UnitInMemoryLoader(unit: new UnitDTOBuilder()->build());
        $handler = new GetUnitQueryHandler($loader);

        // When
        $handler(new GetUnitQuery('slug-1'));

        // Then
        $this->assertSame(['slug-1'], $loader->getCalls);
    }
}

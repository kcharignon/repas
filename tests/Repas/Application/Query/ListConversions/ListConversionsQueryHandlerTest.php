<?php

namespace Repas\Tests\Repas\Application\Query\ListConversions;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListConversions\ListConversionsQuery;
use Repas\Repas\Application\Query\ListConversions\ListConversionsQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ConversionInMemoryLoader;

class ListConversionsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $conversions = [new ConversionDTOBuilder()->withId('conversion-1')->build(), new ConversionDTOBuilder()->withId('conversion-2')->build()];
        $loader = new ConversionInMemoryLoader($conversions);
        $handler = new ListConversionsQueryHandler($loader);

        // When
        $result = $handler(new ListConversionsQuery('admin-1'));

        // Then
        $this->assertSame($conversions, $result);
        $this->assertSame(1, $loader->listCalls);
        $this->assertSame(['admin-1'], $loader->listViewers);
    }
}

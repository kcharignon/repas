<?php

namespace Repas\Tests\Repas\Application\Query\GetConversion;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetConversion\GetConversionQuery;
use Repas\Repas\Application\Query\GetConversion\GetConversionQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ConversionInMemoryLoader;

class GetConversionQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $conversion = new ConversionDTOBuilder()->withId('conversion-1')->build();
        $handler = new GetConversionQueryHandler(new ConversionInMemoryLoader(conversion: $conversion));

        // When
        $result = $handler(new GetConversionQuery('conversion-1'));

        // Then
        $this->assertSame($conversion, $result);
    }

    public function testForwardsTheIdentifierToTheLoader(): void
    {
        // Given
        $loader = new ConversionInMemoryLoader(conversion: new ConversionDTOBuilder()->build());
        $handler = new GetConversionQueryHandler($loader);

        // When
        $handler(new GetConversionQuery('conversion-1'));

        // Then
        $this->assertSame(['conversion-1'], $loader->getCalls);
    }
}

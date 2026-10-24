<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\ConversionGraph;

class ConversionGraphTest extends TestCase
{
    /**
     * @return iterable<string, array{list<array{string, string, float}>, string, string, ?float}>
     */
    public static function coefficients(): iterable
    {
        $conversions = [['kilo', 'gramme', 1000.0], ['unite', 'gramme', 60.0], ['boite', 'unite', 12.0]];

        yield 'la même unité' => [$conversions, 'kilo', 'kilo', 1.0];
        yield 'une étape dans le sens de la conversion' => [$conversions, 'kilo', 'gramme', 1000.0];
        yield 'une étape dans le sens inverse (1 / coefficient)' => [$conversions, 'gramme', 'kilo', 0.001];
        yield 'deux étapes' => [$conversions, 'boite', 'gramme', 720.0];
        yield 'trois étapes dans le sens inverse' => [$conversions, 'kilo', 'boite', 1000.0 / 60.0 / 12.0];
        yield 'une unité sans conversion' => [$conversions, 'tranche', 'gramme', null];
        yield 'un graphe vide' => [[], 'kilo', 'gramme', null];
        yield 'le chemin le plus court gagne' => [[['a', 'b', 2.0], ['b', 'c', 3.0], ['a', 'c', 10.0]], 'a', 'c', 10.0];
    }

    /**
     * @param list<array{string, string, float}> $conversions
     */
    #[DataProvider('coefficients')]
    public function testCoefficientFollowsTheShortestPathInBothDirections(array $conversions, string $start, string $target, ?float $expected): void
    {
        // Given
        $graph = ConversionGraph::fromConversions($conversions);

        // When
        $coefficient = $graph->coefficient($start, $target);

        // Then
        if ($expected === null) {
            $this->assertNull($coefficient);
        } else {
            $this->assertEqualsWithDelta($expected, $coefficient, 1e-12);
        }
    }

    public function testWithAddsAConversionInBothDirectionsAndLeavesTheOriginalGraphUntouched(): void
    {
        // Given : kilo <-> gramme ; la tranche n'est reliée à rien
        $graph = ConversionGraph::fromConversions([['kilo', 'gramme', 1000.0]]);

        // When
        $extended = $graph->with('tranche', 'gramme', 25.0);

        // Then : la tranche se convertit en gramme, en kilo, et inversement ; le graphe d'origine ne la connaît toujours pas
        $this->assertEqualsWithDelta(25.0, $extended->coefficient('tranche', 'gramme'), 1e-12);
        $this->assertEqualsWithDelta(0.025, $extended->coefficient('tranche', 'kilo'), 1e-12);
        $this->assertEqualsWithDelta(0.04, $extended->coefficient('gramme', 'tranche'), 1e-12);
        $this->assertNull($graph->coefficient('tranche', 'gramme'));
        $this->assertEqualsWithDelta(1000.0, $extended->coefficient('kilo', 'gramme'), 1e-12);
    }

    public function testWithOnAnEmptyGraphCreatesItsFirstEdges(): void
    {
        // When
        $graph = new ConversionGraph([])->with('a', 'b', 4.0);

        // Then
        $this->assertEqualsWithDelta(4.0, $graph->coefficient('a', 'b'), 1e-12);
        $this->assertEqualsWithDelta(0.25, $graph->coefficient('b', 'a'), 1e-12);
    }
}

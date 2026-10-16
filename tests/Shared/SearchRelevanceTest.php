<?php

namespace Repas\Tests\Shared;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\SearchRelevance;

class SearchRelevanceTest extends TestCase
{
    public function testTheExactNameIsTheMostRelevant(): void
    {
        $this->assertSame(1.0, SearchRelevance::score('tomate', 'tomate'));
        $this->assertLessThan(1.0, SearchRelevance::score('tomate', 'tomate-cerise'));
    }

    public function testTheShortestNameStartingWithTheSearchComesFirst(): void
    {
        // Given
        $names = ['tomate-cerise', 'tomate', 'sauce-tomate'];

        // When
        usort($names, static fn(string $a, string $b): int => SearchRelevance::score('tom', $b) <=> SearchRelevance::score('tom', $a));

        // Then
        $this->assertSame(['tomate', 'sauce-tomate', 'tomate-cerise'], $names);
    }

    public function testAWordOfTheNameCanBeSearched(): void
    {
        $this->assertGreaterThanOrEqual(SearchRelevance::MINIMUM, SearchRelevance::score('cerise', 'tomate-cerise'));
        $this->assertGreaterThanOrEqual(SearchRelevance::MINIMUM, SearchRelevance::score('de-terre', 'pomme-de-terre'));
    }

    public function testATypoIsForgiven(): void
    {
        $this->assertGreaterThanOrEqual(SearchRelevance::MINIMUM, SearchRelevance::score('tomtae', 'tomate'));
        $this->assertGreaterThanOrEqual(SearchRelevance::MINIMUM, SearchRelevance::score('choclat', 'chocolat'));
    }

    public function testATypoRanksBelowTheExactWord(): void
    {
        $this->assertGreaterThan(SearchRelevance::score('tomtae', 'sauce-tomate'), SearchRelevance::score('tomate', 'sauce-tomate'));
    }

    public function testTheMiddleOfAWordIsNotAnAnswer(): void
    {
        $this->assertLessThan(SearchRelevance::MINIMUM, SearchRelevance::score('riz', 'chorizo'));
    }

    public function testAnUnrelatedNameIsBelowTheMinimum(): void
    {
        $this->assertLessThan(SearchRelevance::MINIMUM, SearchRelevance::score('creme', 'caramel'));
        $this->assertLessThan(SearchRelevance::MINIMUM, SearchRelevance::score('tomate', 'comte'));
    }

    public function testNothingIsRelevantToAnEmptyText(): void
    {
        $this->assertSame(0.0, SearchRelevance::score('', 'tomate'));
        $this->assertSame(0.0, SearchRelevance::score('tomate', ''));
    }
}

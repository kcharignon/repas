<?php

namespace Repas\Tests\Shared;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\JaroWinkler;

class JaroWinklerTest extends TestCase
{
    public static function similarityProvider(): array
    {
        return [
            // Valeurs de référence de l'article de Winkler (1990)
            'lettres permutées' => ['martha', 'marhta', 0.9611],
            'autre mot' => ['dwayne', 'duane', 0.84],
            'début différent' => ['dixon', 'dicksonx', 0.8133],
            'identiques' => ['tomate', 'tomate', 1.0],
            'rien en commun' => ['abc', 'xyz', 0.0],
            'deux vides' => ['', '', 1.0],
            'un vide' => ['tomate', '', 0.0],
            'sans accent ni majuscule, lettre pour lettre' => ['crème', 'creme', 0.8933],
        ];
    }

    #[DataProvider('similarityProvider')]
    public function testSimilarity(string $first, string $second, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, JaroWinkler::similarity($first, $second), 0.0001);
    }

    public function testSimilarityIsSymmetric(): void
    {
        $this->assertSame(JaroWinkler::similarity('tomtae', 'tomate'), JaroWinkler::similarity('tomate', 'tomtae'));
    }

    public function testACommonBeginningRanksHigherThanACommonEnding(): void
    {
        $this->assertGreaterThan(JaroWinkler::similarity('xtomate', 'tomatex'), JaroWinkler::similarity('tomatex', 'tomatey'));
    }
}

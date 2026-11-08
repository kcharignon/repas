<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\Marmiton\MarmitonServings;

class MarmitonServingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, int}>
     */
    public static function yields(): iterable
    {
        yield 'des personnes' => ['6 personnes', 6];
        yield 'une fourchette' => ['4 à 6 personnes', 4];
        yield 'des madeleines' => ['12 madeleines', 12];
        yield 'pour 8' => ['pour 8', 8];
        yield 'le nombre seul' => ['6', 6];
        yield 'une personne' => ['1 personne', 1];
        yield 'cent' => ['100 personnes', 100];
        yield 'cent un : par défaut' => ['101 personnes', 4];
        yield 'zéro : par défaut' => ['0 personne', 4];
        yield 'trop de chiffres : par défaut' => ['10000000000000000000000 parts', 4];
        yield 'zéros en tête' => ['004 parts', 4];
        yield 'décimal : le premier nombre' => ['1,5 litre', 1];
        yield 'sans nombre : par défaut' => ['quelques parts', 4];
        yield 'vide : par défaut' => ['', 4];
        yield 'absent : par défaut' => [null, 4];
        yield 'espaces : par défaut' => ['   ', 4];
        yield 'insécable' => ["pour\u{00A0}8\u{00A0}personnes", 8];
        yield 'un texte très long' => [str_repeat('a', 10_000) . ' 7', 7];
    }

    #[DataProvider('yields')]
    public function testFrom(?string $yield, int $expected): void
    {
        // When / Then
        $this->assertSame($expected, MarmitonServings::from($yield));
    }
}

<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\QuantityBounds;

/**
 * Les bornes des quantités et des coefficients : toujours un nombre fini, jamais zéro, négatif ni démesuré.
 */
class QuantityBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function quantities(): iterable
    {
        yield 'une quantité ordinaire' => [500.0, true];
        yield 'une fraction' => [0.25, true];
        yield 'la plus petite fraction positive' => [PHP_FLOAT_MIN, true];
        yield 'un million' => [1_000_000.0, true];
        yield 'un million et un' => [1_000_001.0, false];
        yield 'zéro' => [0.0, false];
        yield 'zéro négatif' => [-0.0, false];
        yield 'négatif' => [-1.0, false];
        yield 'infini' => [INF, false];
        yield 'moins l\'infini' => [-INF, false];
        yield 'indéfini' => [NAN, false];
        yield 'le plus grand flottant' => [PHP_FLOAT_MAX, false];
    }

    #[DataProvider('quantities')]
    public function testQuantity(float $value, bool $expected): void
    {
        // When / Then
        $this->assertSame($expected, QuantityBounds::isQuantity($value));
    }

    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function coefficients(): iterable
    {
        yield 'un coefficient ordinaire' => [1000.0, true];
        yield 'un' => [1.0, true];
        yield 'un millième' => [0.001, true];
        yield 'la borne basse' => [0.000001, true];
        yield 'la borne haute' => [1_000_000.0, true];
        yield 'l\'inverse de la borne basse (conversion écrite à l\'envers)' => [1 / 0.000001, true];
        yield 'l\'inverse de la borne haute (conversion écrite à l\'envers)' => [1 / 1_000_000.0, true];
        yield 'sous la borne basse' => [0.0000001, false];
        yield 'au-dessus de la borne haute' => [1_000_001.0, false];
        yield 'zéro' => [0.0, false];
        yield 'négatif' => [-2.0, false];
        yield 'infini' => [INF, false];
        yield 'moins l\'infini' => [-INF, false];
        yield 'indéfini' => [NAN, false];
    }

    #[DataProvider('coefficients')]
    public function testCoefficient(float $value, bool $expected): void
    {
        // When / Then
        $this->assertSame($expected, QuantityBounds::isCoefficient($value));
    }
}

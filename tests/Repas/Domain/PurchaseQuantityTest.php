<?php

namespace Repas\Tests\Repas\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\PurchaseQuantity;

class PurchaseQuantityTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float}>
     */
    public static function quantities(): iterable
    {
        yield 'une fraction se lit un' => [0.4, 1.0];
        yield 'une quantité très petite se lit un' => [0.006, 1.0];
        yield 'un entier ne bouge pas' => [3.0, 3.0];
        yield 'le un reste un' => [1.0, 1.0];
        yield 'une fraction au-dessus d\'un entier monte' => [1.4, 2.0];
        yield 'juste au-dessus d\'un entier monte' => [2.01, 3.0];
        yield 'le bruit des flottants ne fait pas monter (0,4 x 5)' => [0.4 * 5, 2.0];
        yield 'le bruit des flottants ne fait pas monter (0,1 x 30)' => [0.1 * 30, 3.0];
        yield 'une grande quantité' => [499.2, 500.0];
    }

    #[DataProvider('quantities')]
    public function testRoundsTheQuantityToBuyUpToTheWholeUnit(float $quantity, float $expected): void
    {
        $this->assertSame($expected, PurchaseQuantity::roundedUp($quantity));
    }

    public function testNeverReadsLessThanOne(): void
    {
        // Une ligne qui existe a toujours quelque chose à acheter
        $this->assertSame(1.0, PurchaseQuantity::roundedUp(0.0));
    }
}

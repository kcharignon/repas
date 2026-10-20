<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\PackSize;

class PackSizeTest extends TestCase
{
    #[DataProvider('acceptedSizes')]
    public function testAcceptsAValueInTheBoundsWithAtMostThreeDecimals(float $value, string $unitSlug): void
    {
        // When
        $packSize = PackSize::of($value, $unitSlug);

        // Then
        $this->assertSame([$value, $unitSlug], [$packSize->value, $packSize->unitSlug]);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function acceptedSizes(): iterable
    {
        yield 'vingt tranches' => [20, 'tranche'];
        yield 'la borne basse, un millième' => [0.001, 'tranche'];
        yield 'la borne haute' => [9999, 'tranche'];
        yield 'trois décimales' => [12.345, 'cuillere-a-soupe'];
        yield 'une décimale' => [2.5, 'gramme'];
        yield 'quatre chiffres et trois décimales, juste sous la borne haute' => [9998.999, 'tranche'];
        yield 'un slug avec des chiffres et des tirets' => [6, 'tasse-a-cafe-2'];
        yield 'le slug le plus court' => [1, 'a'];
        yield 'le slug le plus long' => [1, str_repeat('a', 255)];
    }

    #[DataProvider('refusedSizes')]
    public function testRefusesAnythingElse(float $value, string $unitSlug): void
    {
        // Then
        $this->expectExceptionObject(DriveException::packSizeInvalid());

        // When
        PackSize::of($value, $unitSlug);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function refusedSizes(): iterable
    {
        yield 'zéro' => [0, 'tranche'];
        yield 'négatif' => [-20, 'tranche'];
        yield 'sous le millième' => [0.0005, 'tranche'];
        yield 'quatre décimales' => [1.2345, 'tranche'];
        yield 'quatre décimales près d\'un entier' => [19.9999, 'tranche'];
        yield 'au-dessus de la borne haute' => [10000, 'tranche'];
        yield 'juste au-dessus de la borne haute' => [9999.001, 'tranche'];
        yield 'infini' => [INF, 'tranche'];
        yield 'pas un nombre' => [NAN, 'tranche'];
        yield 'unité vide' => [20, ''];
        yield 'unité avec une majuscule' => [20, 'Tranche'];
        yield 'unité avec un accent' => [20, 'tranché'];
        yield 'unité avec une espace' => [20, 'tranche fine'];
        yield 'unité avec un souligné' => [20, 'tranche_fine'];
        yield 'unité avec un saut de ligne final' => [20, "tranche\n"];
        yield 'unité de 256 caractères' => [20, str_repeat('a', 256)];
    }

    public function testLoadRestoresTheStoredValueAsIs(): void
    {
        // When : une valeur déjà contrôlée à l'écriture n'est pas recontrôlée
        $packSize = PackSize::load(20.0, 'tranche');

        // Then
        $this->assertEquals(PackSize::of(20, 'tranche'), $packSize);
    }
}

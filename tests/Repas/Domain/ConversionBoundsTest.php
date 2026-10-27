<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * Le coefficient d'une conversion est un nombre fini de 0,000001 à 1 000 000, quelle que soit la porte par laquelle il entre. Une conversion
 * déjà enregistrée se lit et se copie sans être jugée.
 */
class ConversionBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidCoefficients(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'négatif' => [-1.0];
        yield 'infini' => [INF];
        yield 'indéfini' => [NAN];
        yield 'démesuré' => [2_000_000.0];
        yield 'minuscule' => [0.0000001];
    }

    #[DataProvider('invalidCoefficients')]
    public function testAConversionCannotBeCreatedWithAnInvalidCoefficient(float $coefficient): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::invalidCoefficient());

        // When
        Conversion::create('id', new UnitBuilder()->isKilo()->build(), new UnitBuilder()->isGramme()->build(), $coefficient, null);
    }

    #[DataProvider('invalidCoefficients')]
    public function testAConversionCannotBeChangedToAnInvalidCoefficient(float $coefficient): void
    {
        // Given
        $conversion = new ConversionBuilder()->isKiloToGramme()->build();

        // Then
        $this->expectExceptionObject(ConversionException::invalidCoefficient());

        // When
        $conversion->update($conversion->getStartUnit(), $conversion->getEndUnit(), $coefficient);
    }

    public function testTheLimitsOfTheRangeAreAccepted(): void
    {
        // Given
        $kilo = new UnitBuilder()->isKilo()->build();
        $gramme = new UnitBuilder()->isGramme()->build();

        // When / Then
        $this->assertSame(0.000001, Conversion::create('a', $kilo, $gramme, 0.000001, null)->getCoefficient());
        $this->assertSame(1_000_000.0, Conversion::create('b', $kilo, $gramme, 1_000_000.0, null)->getCoefficient());
    }

    public function testAnOlderConversionIsCopiedAsItIs(): void
    {
        // Given : un coefficient d'avant les bornes
        $original = new ConversionBuilder()->isKiloToGramme()->withCoefficient(5_000_000.0)->build();
        $ingredient = new \Repas\Tests\Helper\Builder\IngredientBuilder()->isButter()->build();

        // When
        $copy = Conversion::copyFromOriginal('copy', $original, $ingredient);

        // Then
        $this->assertSame(5_000_000.0, $copy->getCoefficient());
    }
}

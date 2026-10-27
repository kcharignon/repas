<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Model\Dimension;

class UnitTest extends TestCase
{
    public function testCreateWithoutMeasure(): void
    {
        // When
        $unit = Unit::create('Pincée', 'pin');

        // Then
        $this->assertSame(['pincee', null, null], [$unit->getSlug(), $unit->getDimension(), $unit->getBaseFactor()]);
    }

    public function testCreateWithItsMeasure(): void
    {
        // When
        $unit = Unit::create('Kilo', 'kg', Dimension::WEIGHT, 1000.0);

        // Then
        $this->assertSame([Dimension::WEIGHT, 1000.0], [$unit->getDimension(), $unit->getBaseFactor()]);
    }

    /**
     * @return iterable<string, array{?Dimension, ?float, string}>
     */
    public static function invalidMeasures(): iterable
    {
        yield 'dimension sans facteur' => [Dimension::WEIGHT, null, UnitException::MEASURE_INCOMPLETE];
        yield 'facteur sans dimension' => [null, 1000.0, UnitException::MEASURE_INCOMPLETE];
        yield 'facteur nul' => [Dimension::WEIGHT, 0.0, UnitException::BASE_FACTOR_INVALID];
        yield 'facteur négatif' => [Dimension::VOLUME, -1.0, UnitException::BASE_FACTOR_INVALID];
        yield 'facteur infini' => [Dimension::VOLUME, INF, UnitException::BASE_FACTOR_INVALID];
        yield 'facteur indéfini' => [Dimension::COUNT, NAN, UnitException::BASE_FACTOR_INVALID];
        yield 'facteur démesuré' => [Dimension::WEIGHT, 1_000_001.0, UnitException::BASE_FACTOR_INVALID];
        yield 'facteur minuscule' => [Dimension::WEIGHT, 0.0000001, UnitException::BASE_FACTOR_INVALID];
    }

    #[DataProvider('invalidMeasures')]
    public function testCreateRefusesAnInvalidMeasure(?Dimension $dimension, ?float $baseFactor, string $message): void
    {
        // Then
        $this->expectException(UnitException::class);
        $this->expectExceptionMessage($message);
        $this->expectExceptionCode(422);

        // When
        Unit::create('Pincée', 'pin', $dimension, $baseFactor);
    }

    public function testSetMeasureReplacesTheWholePair(): void
    {
        // Given
        $unit = Unit::create('Kilo', 'kg', Dimension::WEIGHT, 1000.0);

        // When
        $unit->setMeasure(Dimension::PACKAGE, 1.0);

        // Then
        $this->assertSame([Dimension::PACKAGE, 1.0], [$unit->getDimension(), $unit->getBaseFactor()]);
    }

    public function testSetMeasureToNullClearsIt(): void
    {
        // Given
        $unit = Unit::create('Kilo', 'kg', Dimension::WEIGHT, 1000.0);

        // When
        $unit->setMeasure(null, null);

        // Then
        $this->assertSame([null, null], [$unit->getDimension(), $unit->getBaseFactor()]);
    }

    #[DataProvider('invalidMeasures')]
    public function testSetMeasureRefusesAnInvalidMeasureAndKeepsTheCurrentOne(?Dimension $dimension, ?float $baseFactor, string $message): void
    {
        // Given
        $unit = Unit::create('Kilo', 'kg', Dimension::WEIGHT, 1000.0);

        // When
        try {
            $unit->setMeasure($dimension, $baseFactor);
            $this->fail('UnitException attendue');
        } catch (UnitException $exception) {
            // Then
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame([Dimension::WEIGHT, 1000.0], [$unit->getDimension(), $unit->getBaseFactor()]);
    }

    public function testUpdateLeavesTheMeasureUntouched(): void
    {
        // Given
        $unit = Unit::create('Kilo', 'kg', Dimension::WEIGHT, 1000.0);

        // When
        $unit->update('Kilogramme', 'kilo');

        // Then
        $this->assertSame(['Kilogramme', 'kilo', Dimension::WEIGHT, 1000.0], [$unit->getName(), $unit->getSymbol(), $unit->getDimension(), $unit->getBaseFactor()]);
    }

    public function testLoadRebuildsTheMeasureWithoutControllingIt(): void
    {
        // When
        $unit = Unit::load(['slug' => 'kilo', 'name' => 'Kilo', 'symbol' => 'kg', 'dimension' => Dimension::WEIGHT, 'baseFactor' => 1000.0]);
        $legacy = Unit::load(['slug' => 'pincee', 'name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame([Dimension::WEIGHT, 1000.0], [$unit->getDimension(), $unit->getBaseFactor()]);
        $this->assertSame([null, null], [$legacy->getDimension(), $legacy->getBaseFactor()]);
    }
}

<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ProductObservation;
use Repas\Drive\Domain\Model\ProductPrice;

class ProductObservationTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-29 10:00:00');
    }

    public function testASeenProductKeepsItsLabelAndItsPriceWithTheDateOfTheReading(): void
    {
        // When
        $observation = ProductObservation::seen('  Farine T55 1 kg  ', new ProductPrice(149), $this->now);

        // Then : le libellé est rogné comme celui de la fiche, le prix est daté
        $this->assertSame('Farine T55 1 kg', $observation->label);
        $this->assertSame(149, $observation->priceCents);
        $this->assertEquals($this->now, $observation->priceSeenAt);
        $this->assertTrue($observation->hasPrice());
        $this->assertFalse($observation->isEmpty());
    }

    public function testAProductSeenWithoutPriceHasNoPriceAndNoDate(): void
    {
        // When
        $observation = ProductObservation::seen('Farine', null, $this->now);

        // Then
        $this->assertSame('Farine', $observation->label);
        $this->assertNull($observation->priceCents);
        $this->assertNull($observation->priceSeenAt);
        $this->assertFalse($observation->hasPrice());
    }

    #[DataProvider('refusedLabels')]
    public function testTheLabelFollowsTheRulesOfTheCommonSheet(string $label): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        ProductObservation::seen($label, null, $this->now);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLabels(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'espace insécable seule' => ["\u{00A0}"];
        yield 'saut de ligne' => ["Farine\nT55"];
        yield 'espace de largeur nulle' => ["Farine\u{200B}T55"];
        yield 'trop long' => [str_repeat('a', 256)];
    }

    public function testALegitimateLabelIsAccepted(): void
    {
        // Then : accents, ligature, symboles, espace insécable fine avant l'unité
        $this->assertSame("Œufs frais bio ×6 – 53 g\u{202F}min", ProductObservation::seen("Œufs frais bio ×6 – 53 g\u{202F}min", null, $this->now)->label);
        $this->assertSame(str_repeat('a', 255), ProductObservation::seen(str_repeat('a', 255), null, $this->now)->label);
    }

    public function testASeenAgainProductReplacesTheLabelAndThePriceOnlyWhenOneIsRead(): void
    {
        // Given
        $first = ProductObservation::seen('Farine', new ProductPrice(149), $this->now);
        $later = $this->now->modify('+1 day');

        // When : revu avec un autre libellé et un autre prix
        $again = $first->seenAgain('Farine T55', new ProductPrice(159), $later);

        // Then
        $this->assertSame(['Farine T55', 159], [$again->label, $again->priceCents]);
        $this->assertEquals($later, $again->priceSeenAt);

        // When : revu sans prix lu
        $withoutPrice = $first->seenAgain('Farine T55', null, $later);

        // Then : le libellé change, le prix déjà observé (et sa date) reste
        $this->assertSame(['Farine T55', 149], [$withoutPrice->label, $withoutPrice->priceCents]);
        $this->assertEquals($this->now, $withoutPrice->priceSeenAt);
    }

    public function testRepricingChangesOnlyThePrice(): void
    {
        // Given : un libellé sans prix, puis une ligne qui n'a rien d'autre qu'un prix
        $labelled = ProductObservation::seen('Farine', null, $this->now);
        $priced = ProductObservation::priced(new ProductPrice(149), $this->now);

        // When
        $repriced = $labelled->repriced(new ProductPrice(159), $this->now->modify('+1 day'));

        // Then
        $this->assertSame(['Farine', 159], [$repriced->label, $repriced->priceCents]);
        $this->assertNull($priced->label);
        $this->assertSame(149, $priced->priceCents);
        $this->assertFalse($priced->isEmpty());
    }

    public function testWithoutPriceKeepsTheLabelAndAnObservationWithNothingIsEmpty(): void
    {
        // Given
        $observation = ProductObservation::seen('Farine', new ProductPrice(149), $this->now);

        // When
        $withoutPrice = $observation->withoutPrice();

        // Then
        $this->assertSame('Farine', $withoutPrice->label);
        $this->assertNull($withoutPrice->priceCents);
        $this->assertNull($withoutPrice->priceSeenAt);
        $this->assertTrue(ProductObservation::load(null, null, null)->isEmpty());
        $this->assertTrue(ProductObservation::priced(new ProductPrice(149), $this->now)->withoutPrice()->isEmpty());
    }

    public function testLoadingAHalfWrittenPriceIgnoresThePrice(): void
    {
        // When : un prix sans date, une date sans prix
        $priceOnly = ProductObservation::load('Farine', 149, null);
        $dateOnly = ProductObservation::load('Farine', null, $this->now);

        // Then : les deux vont ensemble, sinon l'estimation retombe sur la fiche commune
        $this->assertNull($priceOnly->priceCents);
        $this->assertNull($priceOnly->priceSeenAt);
        $this->assertNull($dateOnly->priceCents);
        $this->assertNull($dateOnly->priceSeenAt);
        $this->assertSame('Farine', $priceOnly->label);
    }
}

<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\CostEstimate;
use Repas\Drive\Domain\Model\EstimateLineInput;
use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\PreferredProduct;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Service\ListCostEstimator;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Domain\Model\Dimension;

class ListCostEstimatorTest extends TestCase
{
    /**
     * Une ligne seule : le statut, le nombre de produits, le prix unitaire et le sous-total attendus.
     *
     * @return iterable<string, array{EstimateLineInput, string, ?int, ?int, ?int}>
     */
    public static function oneLine(): iterable
    {
        yield 'estimée : 2 paquets de 500 g à 1,29 €' => [self::line(self::package(2), self::product(500, 129)), 'ESTIMATED', 2, 129, 258];
        yield 'estimée : 300 g contre 1 kg' => [self::line(self::weight(300), self::product(1000, 199)), 'ESTIMATED', 1, 199, 199];
        yield 'estimée : 1,2 kg contre 500 g' => [self::line(self::weight(1200), self::product(500, 85)), 'ESTIMATED', 3, 85, 255];
        yield 'estimée : un prix à 0 centime reste un prix' => [self::line(self::package(1), self::product(500, 0)), 'ESTIMATED', 1, 0, 0];
        yield 'j\'en ai toujours : ignorée même avec un produit' => [self::line(self::package(1), self::product(500, 129), haveAtHome: true), 'HAVE_AT_HOME', null, null, null];
        yield 'aucun produit' => [self::line(self::package(1), null), 'NO_PRODUCT', null, null, null];
        yield 'produit sans prix' => [self::line(self::package(1), self::product(500, null)), 'NO_PRICE', null, null, null];
        yield 'sans prix et nombre incertain : sans prix d\'abord' => [self::line(self::weight(300), new PreferredProduct('Farine', null, null, null)), 'NO_PRICE', null, null, null];
        yield 'à vérifier : contenu inconnu pour un poids' => [self::line(self::weight(300), new PreferredProduct('Farine', null, new ProductPrice(199), self::at('2026-10-12'))), 'TO_CHECK', null, 199, null];
        yield 'à vérifier : poids contre des pièces' => [self::line(self::weight(300), self::product(6, 199, ContentUnit::PIECE)), 'TO_CHECK', null, 199, null];
        yield 'à vérifier : unités sans contenu, le défaut n\'est pas deviné' => [self::line(self::units(3), new PreferredProduct('Œufs', null, new ProductPrice(299), self::at('2026-10-12'))), 'TO_CHECK', null, 299, null];
        yield 'à vérifier : quantité nulle' => [self::line(self::weight(0), self::product(1000, 199)), 'TO_CHECK', null, 199, null];
        yield 'à vérifier : plus de 99 produits, plafonné donc non fiable' => [self::line(self::package(500), self::product(500, 100)), 'TO_CHECK', null, 100, null];
    }

    #[DataProvider('oneLine')]
    public function testEachLineGetsItsStatusAndItsAmounts(EstimateLineInput $input, string $status, ?int $packs, ?int $unitPrice, ?int $subtotal): void
    {
        // When
        $line = $this->estimator()->estimate([$input])->lines[0];

        // Then
        $this->assertSame([$status, $packs, $unitPrice, $subtotal], [$line->status->value, $line->packs, $line->unitPriceCents, $line->subtotalCents]);
    }

    public function testASizeGivenByThePersonMakesALineWithoutDimensionEstimable(): void
    {
        // Given : 15 tranches de cheddar contre un produit de 340 g qui en contient 20 (DRV-652)
        $slices = new LineQuantity(15, null, null, 'tranche');
        $content = new ProductContent(340, ContentUnit::G);
        $withSize = new PreferredProduct('Cheddar 20 tranches', $content, new ProductPrice(299), self::at('2026-10-12'), PackSize::of(20, 'tranche'));
        $withoutSize = new PreferredProduct('Cheddar 20 tranches', $content, new ProductPrice(299), self::at('2026-10-12'));
        $otherUnit = new PreferredProduct('Cheddar 20 tranches', $content, new ProductPrice(299), self::at('2026-10-12'), PackSize::of(20, 'portion'));

        // When
        $estimate = $this->estimator()->estimate([
            self::line($slices, $withSize, 'avec'),
            self::line($slices, $withoutSize, 'sans'),
            self::line($slices, $otherUnit, 'autre-unite'),
        ]);

        // Then : estimée avec la réponse, à vérifier sans elle ou avec une réponse dans une autre unité
        $this->assertSame(
            [['ESTIMATED', 1, 299], ['TO_CHECK', null, null], ['TO_CHECK', null, null]],
            array_map(static fn($line): array => [$line->status->value, $line->packs, $line->subtotalCents], $estimate->lines),
        );
        $this->assertSame(299, $estimate->totalCents);
    }

    public function testTheTotalIsTheSumOfTheEstimatedLinesAndTheOthersAreListedNotGuessed(): void
    {
        // Given : 12 lignes dont 8 estimées (1 € chacune), 1 sans produit, 1 sans prix, 1 à vérifier, 1 « j'en ai toujours »
        $inputs = [];
        foreach (range(1, 8) as $i) {
            $inputs[] = self::line(self::package(1), self::product(500, 100 + $i), "estimee-$i");
        }
        $inputs[] = self::line(self::package(1), null, 'sans-produit');
        $inputs[] = self::line(self::package(1), self::product(500, null), 'sans-prix');
        $inputs[] = self::line(self::weight(300), new PreferredProduct('Farine', null, new ProductPrice(199), self::at('2026-10-12')), 'a-verifier');
        $inputs[] = self::line(self::package(1), self::product(500, 129), 'a-la-maison', haveAtHome: true);
        $inputs[] = self::line(self::package(1), null, 'sans-produit-2');

        // When
        $estimate = $this->estimator()->estimate($inputs);

        // Then : 101 + … + 108 = 836 centimes ; 8 estimées sur 12 lignes à acheter (« j'en ai toujours » ne compte pas)
        $this->assertSame(array_sum(range(101, 108)), $estimate->totalCents);
        $this->assertSame([8, 4], [$estimate->estimatedLines, $estimate->unestimatedLines]);
        $this->assertEqualsWithDelta(8 / 12, $estimate->coverage, 0.0001);
        $this->assertSame(0.6667, $estimate->coverage);
        $this->assertCount(13, $estimate->lines);
    }

    public function testTheOldestAndTheNewestPriceDatesCoverOnlyTheLinesOfTheTotal(): void
    {
        // Given : un prix du 12, un du 18, un du 1er (ligne à vérifier : hors total), un du 5 (sans produit calculable : hors total)
        $inputs = [
            self::line(self::package(1), self::product(500, 100, seenAt: '2026-10-12 09:30:00'), 'a'),
            self::line(self::package(1), self::product(500, 100, seenAt: '2026-10-18 10:00:00'), 'b'),
            self::line(self::weight(300), new PreferredProduct('Farine', null, new ProductPrice(199), self::at('2026-10-01')), 'c'),
            self::line(self::package(1), self::product(500, 100, seenAt: '2026-10-05 08:00:00'), 'd', haveAtHome: true),
        ];

        // When
        $estimate = $this->estimator()->estimate($inputs);

        // Then
        $this->assertEquals([self::at('2026-10-12 09:30:00'), self::at('2026-10-18 10:00:00')], [$estimate->oldestPriceAt, $estimate->newestPriceAt]);
        $this->assertEquals([self::at('2026-10-12 09:30:00'), self::at('2026-10-18 10:00:00'), self::at('2026-10-01'), null], array_map(static fn($line) => $line->priceSeenAt, $estimate->lines));
    }

    public function testWithoutAnyEstimatedLineThereIsNoTotalNoDateAndNoCoverage(): void
    {
        // Given : rien à acheter sauf une ligne sans produit, et une ligne « j'en ai toujours »
        $inputs = [self::line(self::package(1), null, 'a'), self::line(self::package(1), null, 'b', haveAtHome: true)];

        // When
        $estimate = $this->estimator()->estimate($inputs);

        // Then
        $this->assertSame([0, 0, 1, 0.0, null, null], [$estimate->totalCents, $estimate->estimatedLines, $estimate->unestimatedLines, $estimate->coverage, $estimate->oldestPriceAt, $estimate->newestPriceAt]);
    }

    public function testAnEmptyListOrOnlyHaveAtHomeLinesHasAZeroCoverage(): void
    {
        // When / Then
        $this->assertSame([0, 0, 0, 0.0], $this->summary($this->estimator()->estimate([])));
        $this->assertSame([0, 0, 0, 0.0], $this->summary($this->estimator()->estimate([self::line(self::package(1), null, haveAtHome: true)])));
    }

    public function testEveryLineEstimatedIsAFullCoverage(): void
    {
        // When
        $estimate = $this->estimator()->estimate([self::line(self::package(1), self::product(500, 250), 'a'), self::line(self::package(2), self::product(500, 50), 'b')]);

        // Then
        $this->assertSame([350, 2, 0, 1.0], $this->summary($estimate));
    }

    public function testTheLinesKeepTheOrderAndTheLabelOfTheProduct(): void
    {
        // When
        $lines = $this->estimator()->estimate([self::line(self::package(1), self::product(500, 100, label: 'Beurre doux 250 g'), 'beurre'), self::line(self::package(1), null, 'ail')])->lines;

        // Then
        $this->assertSame([['beurre', 'Beurre doux 250 g'], ['ail', null]], array_map(static fn($line): array => [$line->ingredientSlug, $line->productLabel], $lines));
    }

    /**
     * @return array{int, int, int, float}
     */
    private function summary(CostEstimate $estimate): array
    {
        return [$estimate->totalCents, $estimate->estimatedLines, $estimate->unestimatedLines, $estimate->coverage];
    }

    private function estimator(): ListCostEstimator
    {
        return new ListCostEstimator(new PackCalculator());
    }

    private static function line(LineQuantity $quantity, ?PreferredProduct $product, string $slug = 'farine', bool $haveAtHome = false): EstimateLineInput
    {
        return new EstimateLineInput($slug, ucfirst($slug), $quantity, $haveAtHome, $product);
    }

    private static function product(float $content, ?int $priceCents, ContentUnit $unit = ContentUnit::G, string $seenAt = '2026-10-12 09:30:00', string $label = 'Produit'): PreferredProduct
    {
        return new PreferredProduct($label, new ProductContent($content, $unit), $priceCents === null ? null : new ProductPrice($priceCents), $priceCents === null ? null : self::at($seenAt));
    }

    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    private static function package(float $quantity): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::PACKAGE, 1.0);
    }

    private static function weight(float $grams): LineQuantity
    {
        return new LineQuantity($grams, Dimension::WEIGHT, 1.0);
    }

    private static function units(float $quantity): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::COUNT, 1.0);
    }
}

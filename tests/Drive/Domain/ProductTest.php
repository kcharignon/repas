<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;

class ProductTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-18 10:00:00');
    }

    public function testCreateKeepsWhatTheAgentRead(): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '555111', '3017620422003', '  Farine T55 1 kg ', new ProductContent(1000, ContentUnit::G), new ProductPrice(149, new PricePerMeasure(149, PriceUnit::KG)), $this->now);

        // Then
        $this->assertSame('555111', $product->getRetailerProductId());
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertSame('Farine T55 1 kg', $product->getLabel());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertSame(PriceUnit::KG, $product->getPrice()->perMeasure->unit);
        $this->assertEquals($this->now, $product->getPriceSeenAt());
        $this->assertEquals($this->now, $product->getLastSeenAt());
    }

    public function testWithoutPriceThereIsNoPriceDate(): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);

        // Then
        $this->assertNull($product->getPrice());
        $this->assertNull($product->getPriceSeenAt());
        $this->assertNull($product->getContent());
    }

    public function testTheIdComesFromTheNaturalKey(): void
    {
        // When
        $a = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);
        $b = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Autre libellé', null, null, $this->now);
        $otherStore = Product::create(Retailer::LECLERC, '654321', '555111', null, 'Farine', null, null, $this->now);

        // Then
        $this->assertSame($a->getId(), $b->getId());
        $this->assertNotSame($a->getId(), $otherStore->getId());
        $this->assertSame(Product::idFor(Retailer::LECLERC, '123456', '555111'), $a->getId());
        $this->assertSame('leclerc|123456|555111', $a->getKey());
    }

    #[DataProvider('invalidProducts')]
    public function testRefusesAProductOutOfBounds(string $id, ?string $ean, string $label, ?ProductContent $content): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        Product::create(Retailer::LECLERC, '123456', $id, $ean, $label, $content, null, $this->now);
    }

    /**
     * @return iterable<string, array{string, ?string, string, ?ProductContent}>
     */
    public static function invalidProducts(): iterable
    {
        yield 'identifiant vide' => ['', null, 'Farine', null];
        yield 'identifiant non numérique' => ['12a', null, 'Farine', null];
        yield 'identifiant trop long' => ['1234567890123', null, 'Farine', null];
        yield 'identifiant avec saut de ligne' => ["123\n", null, 'Farine', null];
        yield 'ean trop court' => ['1', '1234567', 'Farine', null];
        yield 'ean trop long' => ['1', '123456789012345', 'Farine', null];
        yield 'ean non numérique' => ['1', '30176204220ab', 'Farine', null];
        yield 'libellé vide' => ['1', null, '   ', null];
        yield 'libellé trop long' => ['1', null, str_repeat('a', 256), null];
        yield 'libellé avec saut de ligne' => ['1', null, "Farine\nT55", null];
        yield 'libellé en UTF-8 invalide' => ['1', null, "Farine \xff", null];
        yield 'contenu nul' => ['1', null, 'Farine', new ProductContent(0, ContentUnit::G)];
        yield 'contenu négatif' => ['1', null, 'Farine', new ProductContent(-5, ContentUnit::G)];
        yield 'contenu démesuré' => ['1', null, 'Farine', new ProductContent(100000.5, ContentUnit::KG)];
        yield 'contenu infini' => ['1', null, 'Farine', new ProductContent(INF, ContentUnit::G)];
        yield 'contenu non numérique' => ['1', null, 'Farine', new ProductContent(NAN, ContentUnit::G)];
    }

    public function testALabelOfTwoHundredFiftyFiveCharactersWithAccentsIsAccepted(): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '1', null, str_repeat('é', 255), new ProductContent(0.001, ContentUnit::KG), null, $this->now);

        // Then
        $this->assertSame(255, mb_strlen($product->getLabel()));
    }

    public function testPricesOutOfBoundsAreRefused(): void
    {
        foreach ([fn() => new ProductPrice(-1), fn() => new ProductPrice(1_000_001), fn() => new ProductPrice(10, new PricePerMeasure(-1, PriceUnit::L)), fn() => new PricePerMeasure(1_000_001, PriceUnit::PIECE)] as $make) {
            try {
                $make();
                $this->fail('Un prix hors des bornes aurait dû être refusé');
            } catch (DriveException $exception) {
                $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
            }
        }
        $this->assertSame(0, new ProductPrice(0)->cents);
        $this->assertSame(1_000_000, new ProductPrice(1_000_000)->cents);
    }

    public function testSeeingAgainReplacesWhatWasReadAndKeepsWhatWasNot(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', '3017620422003', 'Farine', new ProductContent(1000, ContentUnit::G), new ProductPrice(149), $this->now);
        $later = new DateTimeImmutable('2026-10-19 09:00:00');

        // When : l'agent ne lit ni code-barres, ni contenu, ni prix
        $product->see(null, 'Farine de blé T55', null, null, $later);

        // Then
        $this->assertSame('Farine de blé T55', $product->getLabel());
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertEquals($this->now, $product->getPriceSeenAt());
        $this->assertEquals($later, $product->getLastSeenAt());

        // When : il lit un nouveau contenu et un nouveau prix
        $product->see(null, 'Farine de blé T55', new ProductContent(500, ContentUnit::G), new ProductPrice(99), $later);

        // Then
        $this->assertEquals(new ProductContent(500, ContentUnit::G), $product->getContent());
        $this->assertSame(99, $product->getPrice()->cents);
        $this->assertEquals($later, $product->getPriceSeenAt());
    }

    public function testSeeingARefusedValueChangesNothingMore(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $product->see('pas-un-ean', 'Farine', null, null, $this->now);
    }

    public function testSeePriceOnlyTouchesThePrice(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);
        $later = new DateTimeImmutable('2026-10-19 09:00:00');

        // When
        $product->seePrice(null, $later);

        // Then : rien lu, rien ne change
        $this->assertNull($product->getPriceSeenAt());
        $this->assertEquals($this->now, $product->getLastSeenAt());

        // When
        $product->seePrice(new ProductPrice(149), $later);

        // Then
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertEquals($later, $product->getPriceSeenAt());
        $this->assertSame('Farine', $product->getLabel());
    }
}

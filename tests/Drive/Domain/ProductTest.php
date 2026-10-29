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
        yield 'libellé avec séparateur de ligne (U+2028)' => ['1', null, "Farine\u{2028}T55", null];
        yield 'libellé avec séparateur de paragraphe (U+2029)' => ['1', null, "Farine\u{2029}T55", null];
        yield 'libellé avec inversion du sens de lecture (U+202E)' => ['1', null, "Farine \u{202E}gk 1", null];
        yield 'libellé avec isolat bidirectionnel (U+2066)' => ['1', null, "Farine \u{2066}T55", null];
        yield 'libellé avec marque de sens de lecture (U+200F)' => ['1', null, "Farine\u{200F}", null];
        yield 'libellé avec espace de largeur nulle (U+200B)' => ['1', null, "Fa\u{200B}rine", null];
        yield 'libellé réduit à une espace de largeur nulle' => ['1', null, "\u{200B}", null];
        yield "libellé avec marque d'ordre des octets (U+FEFF)" => ['1', null, "\u{FEFF}Farine", null];
        yield 'libellé réduit à un trait d\'union conditionnel (U+00AD)' => ['1', null, "\u{00AD}", null];
        yield 'libellé réduit à une espace insécable' => ['1', null, "\u{00A0}", null];
        yield 'libellé réduit à des espaces Unicode' => ['1', null, "\u{2003}\u{00A0}\u{3000}", null];
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

    public function testALabelWithAccentsSymbolsAndEmojiIsAccepted(): void
    {
        // When : ce que le site écrit vraiment (apostrophe courbe, tiret demi-cadratin, signes, œ, emoji), sans caractère invisible
        $label = "Crème d’été – 12 œufs 6×50 g 2,5 % 🥚 (Bio)";
        $product = Product::create(Retailer::LECLERC, '123456', '1', null, "  $label  ", null, null, $this->now);

        // Then
        $this->assertSame($label, $product->getLabel());
    }

    #[DataProvider('invisibleCharacters')]
    public function testALabelWithAnInvisibleCharacterIsRefused(string $character): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When : au milieu du libellé
        Product::create(Retailer::LECLERC, '123456', '1', null, "Fa{$character}rine", null, null, $this->now);
    }

    /**
     * Chaque caractère ou plage refusé par le motif : ceux d'avant (sens de lecture, largeur nulle, séparateurs) et ceux ajoutés
     * ensuite (format `Cf`, remplisseurs, sélecteurs de variation, TAG). Les plages sont dépliées : un point de code par cas.
     *
     * @return iterable<string, array{string}>
     */
    public static function invisibleCharacters(): iterable
    {
        $singles = [
            0x00AD => 'trait d\'union conditionnel',
            0x034F => 'joint de graphèmes',
            0x061C => 'marque de lettre arabe',
            0x115F => 'remplisseur hangul de tête',
            0x1160 => 'remplisseur hangul de voyelle',
            0x17B4 => 'voyelle inhérente khmère (aq)',
            0x17B5 => 'voyelle inhérente khmère (aa)',
            0x2028 => 'séparateur de ligne',
            0x2029 => 'séparateur de paragraphe',
            0x3164 => 'remplisseur hangul',
            0xFEFF => 'marque d\'ordre des octets',
            0xFFA0 => 'remplisseur hangul demi-chasse',
            0x0600 => 'signe numérique arabe (Cf)',
            0x06DD => 'fin de verset arabe (Cf)',
            0x070F => 'abréviation syriaque (Cf)',
            0xFFF9 => 'ancre d\'annotation interlinéaire (Cf)',
            0x110BD => 'signe numérique kaithi (Cf)',
            0x13430 => 'contrôle de hiéroglyphe égyptien (Cf)',
            0x1D173 => 'contrôle musical (Cf)',
        ];
        foreach ($singles as $codePoint => $name) {
            yield sprintf('U+%04X %s', $codePoint, $name) => [mb_chr($codePoint, 'UTF-8')];
        }

        $ranges = [
            [0x200B, 0x200F, 'largeur nulle et sens de lecture'],
            [0x202A, 0x202E, 'inversion du sens de lecture'],
            [0x2060, 0x2064, 'joint de mots et opérateurs mathématiques invisibles'],
            [0x2066, 0x2069, 'isolat bidirectionnel'],
            [0x206A, 0x206F, 'format obsolète (Cf)'],
            [0x180B, 0x180F, 'sélecteur de variation mongol'],
            [0xFE00, 0xFE0F, 'sélecteur de variation'],
            [0xE0000, 0xE007F, 'caractère de TAG'],
            [0xE0100, 0xE01EF, 'sélecteur de variation (supplément)'],
        ];
        foreach ($ranges as [$from, $to, $name]) {
            for ($codePoint = $from; $codePoint <= $to; ++$codePoint) {
                yield sprintf('U+%04X %s', $codePoint, $name) => [mb_chr($codePoint, 'UTF-8')];
            }
        }
    }

    #[DataProvider('legitimateLabels')]
    public function testALegitimateLabelIsAcceptedAsIs(string $label): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '1', null, $label, null, null, $this->now);

        // Then
        $this->assertSame($label, $product->getLabel());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legitimateLabels(): iterable
    {
        yield 'lettres accentuées' => ['Crème brûlée à la façon de l’hôtel, île, noël, maïs, où'];
        yield 'ligatures œ et Œ' => ['Œufs de poule, bœuf, cœur, sœur'];
        yield 'ligatures typographiques' => ["Ba\u{FB01}ne \u{FB02}an"];
        yield 'accent combinant (décomposé)' => ["Cre\u{0300}me bru\u{0302}le\u{0301}e"];
        yield 'symboles de marque' => ['Marque® Produit™ © 2026'];
        yield 'degré, euro, pourcent, esperluette' => ['Eau à 12 °C, 2,50 € le lot, 3 % de sel & poivre'];
        yield 'apostrophe courbe et guillemets' => ['Pommes d’api « Bio »'];
        yield 'espace insécable (U+00A0)' => ["Lait demi-écrémé 1\u{00A0}L"];
        yield 'espace fine insécable (U+202F)' => ["Farine T55 1\u{202F}kg à 1,49\u{202F}€"];
        yield 'espace normale et signes' => ['Pâtes 500 g (x2) - lot de 3 + 1 offert'];
        yield 'emoji d\'un seul caractère' => ['Œufs 🥚 x12'];
        yield 'lettres non latines' => ['Kimchi 김치, ラーメン, Ελιά'];
    }

    public function testSeeingALabelWithAnInvisibleCharacterChangesNothing(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $product->complete(null, "Farine \u{202E}gk 1", null, null, $this->now);
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

    public function testSeeingAgainNeverRewritesWhatIsKnown(): void
    {
        // Given : une fiche complète
        $product = Product::create(Retailer::LECLERC, '123456', '555111', '3017620422003', 'Farine', new ProductContent(1000, ContentUnit::G), new ProductPrice(149), $this->now);
        $later = new DateTimeImmutable('2026-10-19 09:00:00');

        // When : un autre agent renvoie un autre libellé, un autre code-barres, un autre contenu et un autre prix
        $product->complete('40000000', 'Texte trompeur', new ProductContent(100, ContentUnit::G), new ProductPrice(1), $later);

        // Then : premier écrivain gagnant, seule la date de vue bouge
        $this->assertSame('Farine', $product->getLabel());
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertEquals($this->now, $product->getPriceSeenAt());
        $this->assertEquals($later, $product->getLastSeenAt());
    }

    public function testSeeingAgainOnlyFillsWhatIsStillEmpty(): void
    {
        // Given : une fiche sans code-barres, sans contenu ni prix
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);
        $later = new DateTimeImmutable('2026-10-19 09:00:00');

        // When : l'agent lit le contenu seulement
        $product->complete(null, 'Farine de blé T55', new ProductContent(500, ContentUnit::G), null, $later);

        // Then
        $this->assertSame('Farine', $product->getLabel());
        $this->assertNull($product->getEan());
        $this->assertEquals(new ProductContent(500, ContentUnit::G), $product->getContent());
        $this->assertSame([null, null], [$product->getPrice()?->cents, $product->getPriceSeenAt()]);

        // When : il lit ensuite un code-barres, un autre contenu et un prix
        $product->complete('3017620422003', 'Farine', new ProductContent(900, ContentUnit::G), new ProductPrice(99), $later);

        // Then : le code-barres et le prix manquants sont remplis, le contenu connu reste
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertEquals(new ProductContent(500, ContentUnit::G), $product->getContent());
        $this->assertSame(99, $product->getPrice()?->cents);
        $this->assertEquals($later, $product->getPriceSeenAt());
    }

    public function testSeeingARefusedValueIsStillRefusedEvenWhenItWouldNotBeUsed(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $product->complete('pas-un-ean', 'Farine', null, null, $this->now);
    }

    public function testCreateKeepsTheRayonAndTheFamilyOfTheStore(): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now, 4001, 4101);
        $without = Product::create(Retailer::LECLERC, '123456', '555112', null, 'Sel', null, null, $this->now);

        // Then : facultatifs, ils valent null quand l'agent ne les a pas lus
        $this->assertSame([4001, 4101], [$product->getRayonId(), $product->getFamilyId()]);
        $this->assertSame([null, null], [$without->getRayonId(), $without->getFamilyId()]);
    }

    #[DataProvider('invalidCatalogIds')]
    public function testRefusesARayonOrAFamilyOutOfBounds(?int $rayonId, ?int $familyId): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now, $rayonId, $familyId);
    }

    /**
     * @return iterable<string, array{?int, ?int}>
     */
    public static function invalidCatalogIds(): iterable
    {
        yield 'rayon nul' => [0, null];
        yield 'rayon négatif' => [-4001, null];
        yield 'rayon trop grand pour un INT' => [2147483648, null];
        yield 'famille nulle' => [null, 0];
        yield 'famille négative' => [4001, -1];
        yield 'famille trop grande pour un INT' => [4001, 2147483648];
    }

    public function testTheBoundsOfACatalogIdAreOneAndTheLargestInt(): void
    {
        // When
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now, 1, 2147483647);

        // Then
        $this->assertSame([1, 2147483647], [$product->getRayonId(), $product->getFamilyId()]);
        $this->assertSame([false, true, true, false], array_map(Product::isCatalogId(...), [0, 1, 2147483647, 2147483648]));
    }

    public function testSeeingAgainFillsAMissingRayonButNeverReplacesOneOrEmptiesIt(): void
    {
        // Given : une fiche d'avant le rayon (rien), puis une fiche complète
        $old = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);
        $complete = Product::create(Retailer::LECLERC, '123456', '555112', null, 'Sel', null, null, $this->now, 4001, 4101);
        $later = new DateTimeImmutable('2026-10-19 09:00:00');

        // When : un agent qui connaît le rayon complète l'ancienne fiche, un autre propose d'autres valeurs ou aucune
        $old->complete(null, 'Farine', null, null, $later, 4002, 4102);
        $complete->complete(null, 'Sel', null, null, $later, 4999, 4999);
        $complete->complete(null, 'Sel', null, null, $later);

        // Then : le rayon manquant est rempli, le rayon connu reste (ni remplacé, ni effacé par l'absence)
        $this->assertSame([4002, 4102], [$old->getRayonId(), $old->getFamilyId()]);
        $this->assertSame([4001, 4101], [$complete->getRayonId(), $complete->getFamilyId()]);
    }

    public function testSeeingAgainWithoutRayonLeavesAMissingRayonMissing(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now);

        // When
        $product->complete(null, 'Farine', null, null, $this->now);

        // Then
        $this->assertSame([null, null], [$product->getRayonId(), $product->getFamilyId()]);
    }

    public function testSeeingARayonOutOfBoundsIsRefusedEvenWhenAKnownRayonWouldWin(): void
    {
        // Given
        $product = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Farine', null, null, $this->now, 4001, null);

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $product->complete(null, 'Farine', null, null, $this->now, 0, null);
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

    public function testValidLabelTrimsAndAppliesTheRulesOfTheSheet(): void
    {
        // Then : le libellé rogné, en accents et symboles
        $this->assertSame('Œufs bio ×6', Product::validLabel("  Œufs bio ×6  "));
        // Then : les mêmes refus que la fiche commune
        foreach (['', '   ', "Farine\nT55", "Farine\u{200B}T55", str_repeat('a', 256)] as $label) {
            try {
                Product::validLabel($label);
                $this->fail('DRIVE_CHOICE_INVALID attendu');
            } catch (DriveException $exception) {
                $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
            }
        }
    }
}

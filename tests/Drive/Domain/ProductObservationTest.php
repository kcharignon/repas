<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ProductObservation;

class ProductObservationTest extends TestCase
{
    public function testASeenProductKeepsItsTrimmedLabel(): void
    {
        // When
        $observation = ProductObservation::seen('  Farine T55 1 kg  ');

        // Then : le libellé est rogné comme celui de la fiche
        $this->assertSame('Farine T55 1 kg', $observation->label);
        $this->assertFalse($observation->isEmpty());
    }

    public function testAnObservationHasNoPrice(): void
    {
        // Then : le prix est un fait commun du magasin (DRV-770), il vit sur la fiche et jamais sur la ligne du compte
        $this->assertSame(['label'], array_keys(get_object_vars(ProductObservation::seen('Farine'))));
    }

    #[DataProvider('refusedLabels')]
    public function testTheLabelFollowsTheRulesOfTheCommonSheet(string $label): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        ProductObservation::seen($label);
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
        $this->assertSame("Œufs frais bio ×6 – 53 g\u{202F}min", ProductObservation::seen("Œufs frais bio ×6 – 53 g\u{202F}min")->label);
        $this->assertSame(str_repeat('a', 255), ProductObservation::seen(str_repeat('a', 255))->label);
    }

    public function testAnObservationWithNothingIsEmpty(): void
    {
        // Then
        $this->assertTrue(ProductObservation::load(null)->isEmpty());
        $this->assertSame('Farine', ProductObservation::load('Farine')->label);
    }
}

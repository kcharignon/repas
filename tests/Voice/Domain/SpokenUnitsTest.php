<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Les unités du catalogue telles qu'on les dit à voix haute (ADR 0029 décision 5) : retrouvées d'après leur texte, ou reconnues en tête d'une
 * phrase. L'accord avec le modèle d'interaction et avec le catalogue est prouvé par `DocsVoiceSkillTest`.
 */
class SpokenUnitsTest extends TestCase
{
    #[DataProvider('words')]
    public function testAUnitIsFoundFromTheWordsThatNameIt(?string $said, ?string $expected): void
    {
        $this->assertSame($expected, new SpokenUnits()->slugOf($said));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function words(): iterable
    {
        yield 'singulier' => ['paquet', 'paquet'];
        yield 'pluriel' => ['paquets', 'paquet'];
        yield 'majuscules' => ['Kilos', 'kilo'];
        yield 'espaces' => ['  litres ', 'litre'];
        yield 'plusieurs mots' => ['cuillères à café', 'cuillere-a-cafe'];
        yield 'pièce' => ['pièces', 'unite'];
        yield 'inconnue' => ['poignées', null];
        yield 'vide' => ['', null];
        yield 'rien' => [null, null];
    }

    public function testEveryFormIsLowercaseAndNamedOnce(): void
    {
        $all = [];
        foreach (SpokenUnits::FORMS as $slug => $forms) {
            foreach ($forms as $form) {
                $this->assertSame(mb_strtolower($form), $form, $slug);
                $all[] = $form;
            }
        }

        $this->assertSame(array_values(array_unique($all)), $all, 'une façon de dire une unité ne désigne pas deux unités');
    }

    public function testNothingIsRemovedWithoutADeAfterTheUnit(): void
    {
        $this->assertNull(new SpokenUnits()->splitLeading('kilos tomates'));
        $this->assertNull(new SpokenUnits()->splitLeading('kilos'));
        $this->assertNull(new SpokenUnits()->splitLeading("kilos d'"));
        $this->assertSame(['unitSlug' => 'kilo', 'rest' => 'tomates'], new SpokenUnits()->splitLeading('kilos de tomates'));
    }
}

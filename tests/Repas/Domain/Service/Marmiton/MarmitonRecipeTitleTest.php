<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\Marmiton\MarmitonRecipeTitle;

class MarmitonRecipeTitleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'le cas de l\'ADR' => ['Tarte au citron meringuée : la meilleure recette', 'Tarte au citron meringuée'];
        yield 'sans espace avant les deux-points' => ['Tarte au citron: la meilleure recette', 'Tarte au citron'];
        yield 'casse ignorée' => ['Tarte au citron : LA MEILLEURE RECETTE', 'Tarte au citron'];
        yield 'sans « la »' => ['Quiche lorraine : meilleure recette', 'Quiche lorraine'];
        yield 'recette facile' => ['Gratin dauphinois : recette facile', 'Gratin dauphinois'];
        yield 'recette simple et rapide' => ['Crêpes : recette simple et rapide', 'Crêpes : recette simple et rapide'];
        yield 'recette rapide' => ['Crêpes : recette rapide', 'Crêpes'];
        yield 'recette de grand-mère' => ['Blanquette : recette de grand-mère', 'Blanquette'];
        yield 'recette de ma grand mère' => ['Blanquette : recette de ma grand mère', 'Blanquette'];
        yield 'recette du chef' => ['Blanquette : recette du chef', 'Blanquette'];
        yield 'recette de cuisine marmiton' => ['Blanquette : recette de cuisine Marmiton', 'Blanquette'];
        yield 'tiret Marmiton' => ['Blanquette de veau - Marmiton', 'Blanquette de veau'];
        yield 'tiret long Marmiton' => ['Blanquette de veau — Marmiton', 'Blanquette de veau'];
        yield 'barre Marmiton.org' => ['Blanquette de veau | Marmiton.org', 'Blanquette de veau'];
        yield 'deux-points Marmiton' => ['Blanquette de veau : Marmiton', 'Blanquette de veau'];
        yield 'tiret recette' => ['Blanquette de veau - recette facile', 'Blanquette de veau'];
        yield 'espaces normalisées' => ["  Tarte   au\u{00A0}citron  \n: la meilleure recette  ", 'Tarte au citron'];
        yield 'première lettre en majuscule' => ['tarte au citron : la meilleure recette', 'Tarte au citron'];
        yield 'accent en tête' => ['éclairs au chocolat', 'Éclairs au chocolat'];
        yield 'entités HTML' => ['Pâtes à l&#39;ail : la meilleure recette', 'Pâtes à l\'ail'];
        yield 'sans suffixe' => ['Tarte au citron', 'Tarte au citron'];
        yield 'un seul suffixe retiré' => ['Tarte : la meilleure recette : la meilleure recette', 'Tarte : la meilleure recette'];
        yield 'le suffixe au milieu reste' => ['La meilleure recette : le gâteau de Marie', 'La meilleure recette : le gâteau de Marie'];
        yield 'un mot composé n\'est pas un suffixe' => ['Pot-au-feu', 'Pot-au-feu'];
        yield 'un tiret sans espace n\'est pas un suffixe' => ['Salade-marmiton', 'Salade-marmiton'];
        yield 'titre vide' => ['', ''];
        yield 'seulement le suffixe : rendu tel quel après nettoyage' => [': la meilleure recette', ''];
        yield 'seulement des espaces' => ["   \n ", ''];
    }

    #[DataProvider('titles')]
    public function testClean(string $raw, string $expected): void
    {
        // When / Then
        $this->assertSame($expected, MarmitonRecipeTitle::clean($raw));
    }

    public function testTheTitleIsBoundedTo255Characters(): void
    {
        // When
        $title = MarmitonRecipeTitle::clean(str_repeat('é', 400) . ' : la meilleure recette');

        // Then
        $this->assertSame(255, mb_strlen($title));
    }

    public function testInvalidUtf8NeverThrows(): void
    {
        // When / Then
        $this->assertTrue(mb_check_encoding(MarmitonRecipeTitle::clean("Tarte \xFF\xFE : la meilleure recette"), 'UTF-8'));
    }

    public function testPathologicalTitlesAreFast(): void
    {
        // Given
        MarmitonRecipeTitle::clean('Tarte');

        foreach ([str_repeat(' : ', 5_000), str_repeat('recette ', 2_000), str_repeat(' - ', 5_000) . 'marmiton', str_repeat('a', 10_000)] as $raw) {
            // When
            $start = hrtime(true);
            MarmitonRecipeTitle::clean($raw);

            // Then
            $this->assertLessThan(50, (hrtime(true) - $start) / 1_000_000);
        }
    }
}

<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Service\Marmiton\MarmitonIngredientMatcher;

/**
 * Retrouver l'ingrédient d'une ligne de Marmiton sans connaître son rayon (ADR 0037 décision 6) : les règles du rapprochement d'une copie de recette
 * (nom normalisé, faute de frappe à 0,97, même nombre de mots), dans chaque rayon qui a des candidats ; au moindre doute (deux rayons), rien.
 */
class MarmitonIngredientMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function names(): iterable
    {
        yield 'le même nom' => ['farine', 'farine'];
        yield 'la casse et les accents' => ['FARINE', 'farine'];
        yield 'un pluriel' => ['farines', 'farine'];
        yield 'la ligature' => ['oeufs', 'oeuf'];
        yield 'un mot vide' => ['jaunes d\'oeuf', 'jaune-oeuf'];
        yield 'une faute de frappe d\'un mot assez long' => ['chocolatt noir', 'chocolat-noir'];
        yield 'un mot de plus n\'est pas le même ingrédient' => ['farine de blé', null];
        yield 'un nom précisé n\'est pas le même ingrédient' => ['beurre doux', null];
        yield 'un nom inconnu' => ['paprika', null];
        yield 'des numéros différents' => ['sachet 2', null];
    }

    #[DataProvider('names')]
    public function testItMatchesTheSameNameAndOnlyTheSameName(string $name, ?string $expected): void
    {
        // Given
        $candidates = [
            self::candidate('farine', 'Farine', 'boulangerie'),
            self::candidate('oeuf', 'Œuf', 'divers'),
            self::candidate('jaune-oeuf', 'Jaune d\'œuf', 'divers'),
            self::candidate('beurre', 'Beurre', 'fromage'),
            self::candidate('chocolat-noir', 'Chocolat noir', 'sucre'),
            self::candidate('sachet-1', 'Sachet 1', 'divers'),
        ];

        // When
        $match = new MarmitonIngredientMatcher()->match($name, $candidates);

        // Then
        $this->assertSame($expected, $match?->slug);
    }

    public function testTwoDepartmentsThatBothHaveTheNameMakeItDoubtfulSoNothingIsChosen(): void
    {
        // Given : « Tomate » existe en fruit et légume ET en surgelé
        $candidates = [self::candidate('tomate-frais', 'Tomate', 'fruit-et-legume'), self::candidate('tomate-surgelee', 'Tomate', 'surgele')];

        // When & Then : la ligne ne dit pas le rayon, on ne devine pas
        $this->assertNull(new MarmitonIngredientMatcher()->match('tomates', $candidates));
        // Un seul des deux rayons : le candidat est sûr
        $this->assertSame('tomate-frais', new MarmitonIngredientMatcher()->match('tomates', [$candidates[0]])?->slug);
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheCandidates(): void
    {
        // Given
        $candidates = [self::candidate('farine', 'Farine', 'boulangerie'), self::candidate('sucre', 'Sucre', 'boulangerie'), self::candidate('sel', 'Sel', 'divers')];

        // When & Then
        $this->assertSame('sucre', new MarmitonIngredientMatcher()->match('sucre', $candidates)?->slug);
        $this->assertSame('sucre', new MarmitonIngredientMatcher()->match('sucre', array_reverse($candidates))?->slug);
    }

    public function testNoCandidateGivesNothing(): void
    {
        $this->assertNull(new MarmitonIngredientMatcher()->match('farine', []));
    }

    private static function candidate(string $slug, string $name, string $department): IngredientCandidate
    {
        return new IngredientCandidate($slug, $name, $department, 'gramme');
    }
}

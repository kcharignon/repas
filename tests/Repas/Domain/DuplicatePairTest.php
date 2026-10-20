<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\DuplicatePair;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\MatchLevel;
use Repas\Repas\Domain\Model\NameSimilarity;

/**
 * L'ingrédient à garder par défaut d'une proposition (ING-006) : le plus utilisé en recettes, puis en listes, puis le nom le plus court,
 * puis l'ordre alphabétique, puis l'ordre des slugs. Une règle déterministe : aucune égalité n'est tranchée au hasard.
 */
class DuplicatePairTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, int, int, int, string}> nom du premier, nom du second, recettes et listes de chacun, celui qui est gardé
     */
    public static function keepRule(): iterable
    {
        yield 'le plus de recettes' => ['Tomate', 'Tomates', 12, 3, 7, 9, 'first'];
        yield 'le plus de recettes, même si c est le second' => ['Tomate', 'Tomates', 7, 3, 12, 0, 'second'];
        yield 'à égalité de recettes, le plus de listes' => ['Tomate', 'Tomates', 5, 1, 5, 4, 'second'];
        yield 'à égalité d usage, le nom le plus court' => ['Tomates', 'Tomate', 2, 2, 2, 2, 'second'];
        yield 'sans aucun usage, le nom le plus court' => ['Tomate', 'Tomates', 0, 0, 0, 0, 'first'];
        yield 'à égalité de longueur, l ordre alphabétique' => ['Poivre blanc', 'Poivre noire', 0, 0, 0, 0, 'first'];
        yield 'à égalité de longueur, l ordre alphabétique (inversé)' => ['Radis', 'Navet', 1, 1, 1, 1, 'second'];
        yield 'même nom normalisé et même longueur, l ordre des slugs' => ['Tomate', 'tomate', 0, 0, 0, 0, 'first'];
    }

    #[DataProvider('keepRule')]
    public function testTheSuggestedKeepFollowsTheWrittenRule(string $firstName, string $secondName, int $firstRecipes, int $firstLists, int $secondRecipes, int $secondLists, string $expected): void
    {
        // Given
        $pair = new DuplicatePair(
            new IngredientCandidate('a-slug', $firstName, 'rayon', 'kilo'),
            new IngredientCandidate('b-slug', $secondName, 'rayon', 'kilo'),
            new NameSimilarity(MatchLevel::EXACT, 1.0),
        );

        // When
        $keep = $pair->suggestedKeep($firstRecipes, $firstLists, $secondRecipes, $secondLists);

        // Then
        $this->assertSame($expected === 'first' ? 'a-slug' : 'b-slug', $keep->slug);
    }
}

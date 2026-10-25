<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Infrastructure\Voice\SpokenIngredientResolverAdapter;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Tests\Helper\InMemoryLoader\SpokenIngredientCandidatesInMemoryLoader;

/**
 * L'adaptateur seulement (port de candidats simulé) : il charge les candidats de l'utilisateur, laisse le classeur choisir et renvoie un DTO
 * de `Shared`. Le classement lui-même est prouvé par `SpokenIngredientRankerTest`, la lecture des habitudes par le test du Loader.
 */
class SpokenIngredientResolverAdapterTest extends TestCase
{
    public function testItLoadsTheCandidatesOfTheUserAndMapsTheChoiceToTheSharedDto(): void
    {
        // Given : un nom identique à la phrase, seul à lui ressembler
        $candidates = new SpokenIngredientCandidatesInMemoryLoader([
            new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 4),
            new SpokenCandidate('farine', 'farine', 'farine', 'épicerie', 0),
        ]);

        // When
        $resolved = new SpokenIngredientResolverAdapter($candidates)->resolve('user-id', 'des tomates');

        // Then
        $this->assertEquals(new ResolvedIngredient('tomate', 'tomate', 'fruits et légumes', 'sure'), $resolved);
        $this->assertSame(['user-id'], $candidates->calls);
    }

    public function testTheConfidenceIsGivenAsItsStringValue(): void
    {
        // Given : deux noms proches d'habitudes voisines, le classeur n'est pas sûr
        $candidates = new SpokenIngredientCandidatesInMemoryLoader([
            new SpokenCandidate('tomate-cerise', 'tomate-cerise', 'tomate cerise', 'fruits et légumes', 2),
            new SpokenCandidate('tomate-ronde', 'tomate-ronde', 'tomate ronde', 'fruits et légumes', 2),
        ]);

        // When
        $resolved = new SpokenIngredientResolverAdapter($candidates)->resolve('user-id', 'tomate');

        // Then
        $this->assertSame('unsure', $resolved?->confidence);
    }

    public function testAnAccountWithoutIngredientGetsNull(): void
    {
        // Given
        $candidates = new SpokenIngredientCandidatesInMemoryLoader([]);

        // When
        $resolved = new SpokenIngredientResolverAdapter($candidates)->resolve('user-id', 'du pain');

        // Then
        $this->assertNull($resolved);
        $this->assertSame(['user-id'], $candidates->calls);
    }

    public function testAPhraseThatResemblesNoIngredientGetsNull(): void
    {
        // Given
        $candidates = new SpokenIngredientCandidatesInMemoryLoader([new SpokenCandidate('pain', 'pain', 'pain', 'boulangerie', 9)]);

        // When
        $resolved = new SpokenIngredientResolverAdapter($candidates)->resolve('user-id', 'xyz');

        // Then
        $this->assertNull($resolved);
    }
}

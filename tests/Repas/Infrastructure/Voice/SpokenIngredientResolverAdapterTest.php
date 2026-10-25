<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Infrastructure\Voice\SpokenIngredientResolverAdapter;
use Repas\Shared\Application\Dto\IngredientSuggestion;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Tests\Helper\InMemoryLoader\SpokenIngredientCandidatesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenPhraseMemoryInMemoryLoader;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'adaptateur seulement (ports de lecture et bus simulés) : il charge les candidats de l'utilisateur, lit la mémoire de phrase AVANT le
 * classement, laisse le classeur choisir ou proposer et renvoie des DTO de `Shared`. Le classement lui-même est prouvé par
 * `SpokenIngredientRankerTest`, la lecture des habitudes et de la mémoire par les tests des Loaders, le parcours complet par
 * `SpokenIngredientResolverFlowTest`.
 */
class SpokenIngredientResolverAdapterTest extends TestCase
{
    private MockCommandBus $bus;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
    }

    /**
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     */
    private function adapter(array $candidates, array $remembered = [], ?SpokenPhraseMemoryInMemoryLoader &$memory = null, ?SpokenIngredientCandidatesInMemoryLoader &$loader = null): SpokenIngredientResolverAdapter
    {
        $loader = new SpokenIngredientCandidatesInMemoryLoader($candidates);
        $memory = new SpokenPhraseMemoryInMemoryLoader($remembered);

        return new SpokenIngredientResolverAdapter($loader, $memory, $this->bus);
    }

    public function testItLoadsTheCandidatesOfTheUserAndMapsTheChoiceToTheSharedDto(): void
    {
        // Given : un nom identique à la phrase, seul à lui ressembler
        $adapter = $this->adapter([
            new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 4),
            new SpokenCandidate('farine', 'farine', 'farine', 'épicerie', 0),
        ], loader: $candidates);

        // When
        $resolved = $adapter->resolve('user-id', 'des tomates');

        // Then
        $this->assertEquals(new ResolvedIngredient('tomate', 'tomate', 'fruits et légumes', 'sure'), $resolved);
        $this->assertSame(['user-id'], $candidates->calls);
    }

    public function testTheConfidenceIsGivenAsItsStringValue(): void
    {
        // Given : deux noms proches d'habitudes voisines, le classeur n'est pas sûr
        $adapter = $this->adapter([
            new SpokenCandidate('tomate-cerise', 'tomate-cerise', 'tomate cerise', 'fruits et légumes', 2),
            new SpokenCandidate('tomate-ronde', 'tomate-ronde', 'tomate ronde', 'fruits et légumes', 2),
        ]);

        // When
        $resolved = $adapter->resolve('user-id', 'tomate');

        // Then
        $this->assertSame('unsure', $resolved?->confidence);
    }

    public function testAnAccountWithoutIngredientGetsNullAndTheMemoryIsNotRead(): void
    {
        // Given
        $adapter = $this->adapter([], ['pain' => 'pain'], $memory, $candidates);

        // When
        $resolved = $adapter->resolve('user-id', 'du pain');

        // Then
        $this->assertNull($resolved);
        $this->assertSame(['user-id'], $candidates->calls);
        $this->assertSame([], $memory->calls, 'aucun ingrédient : pas de requête pour la mémoire');
    }

    public function testAPhraseThatResemblesNoIngredientGetsNull(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('pain', 'pain', 'pain', 'boulangerie', 9)]);

        // When
        $resolved = $adapter->resolve('user-id', 'xyz');

        // Then
        $this->assertNull($resolved);
    }

    public function testTheMemoryOfThePhraseIsReadBeforeTheRankingAndWins(): void
    {
        // Given : « ciboulette » ne ressemble à rien, mais l'utilisateur a dit que c'était le persil ; la clé est la phrase normalisée
        $adapter = $this->adapter(
            [new SpokenCandidate('persil-u', 'persil', 'persil', 'fruits et légumes', 0), new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)],
            ['ciboulette' => 'persil-u'],
            $memory,
        );

        // When
        $resolved = $adapter->resolve('user-id', 'de la ciboulette');

        // Then
        $this->assertEquals(new ResolvedIngredient('persil-u', 'persil', 'fruits et légumes', 'sure'), $resolved);
        $this->assertSame([['user-id', 'ciboulette']], $memory->calls);
    }

    public function testAMemoryThatNamesAnIngredientThatNoLongerExistsIsIgnored(): void
    {
        // Given : la mémoire désigne un ingrédient supprimé depuis ; le classement reprend la main
        $adapter = $this->adapter([new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)], ['tomate' => 'supprime-u']);

        // When
        $resolved = $adapter->resolve('user-id', 'tomate');

        // Then
        $this->assertSame('tomate-u', $resolved?->slug);
    }

    public function testAPhraseWithoutAnyMeaningfulWordDoesNotReadTheMemory(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)], [], $memory);

        // When
        $resolved = $adapter->resolve('user-id', 'de la');

        // Then
        $this->assertNull($resolved);
        $this->assertSame([], $memory->calls);
    }

    public function testItSuggestsTheClosestIngredientsOfTheUserAsSharedDtos(): void
    {
        // Given
        $adapter = $this->adapter([
            new SpokenCandidate('sirop-u', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'boisson', 0),
            new SpokenCandidate('farine-u', 'farine', 'Farine', 'épicerie', 0),
        ], loader: $candidates);

        // When
        $suggestions = $adapter->suggest('user-id', 'sirop de menthe');

        // Then
        $this->assertEquals([new IngredientSuggestion('sirop-u', 'Sirop fruit rouge', 'boisson')], $suggestions);
        $this->assertSame(['user-id'], $candidates->calls);
    }

    public function testNothingCloseMeansNoSuggestion(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('melon-u', 'melon', 'Melon', 'fruits', 0)]);

        // Then
        $this->assertSame([], $adapter->suggest('user-id', 'zébulon'));
        $this->assertSame([], $this->adapter([])->suggest('user-id', 'zébulon'));
    }

    public function testRememberingDispatchesTheCommandWithThePhraseAsHeard(): void
    {
        // When
        $this->adapter([])->remember('user-id', 'de la ciboulette', 'persil-u');

        // Then : la commande normalise elle-même (la clé est tirée dans le handler)
        $command = $this->bus->dispatched()[0];
        $this->assertInstanceOf(RememberSpokenPhraseCommand::class, $command);
        $this->assertSame(['user-id', 'de la ciboulette', 'persil-u'], [$command->userId, $command->phrase, $command->ingredientSlug]);
    }

    public function testADomainErrorFromTheCommandIsPropagated(): void
    {
        // Given : l'ingrédient est à un autre utilisateur
        $this->bus->willThrow(RememberSpokenPhraseCommand::class, IngredientException::notFound('persil-u'));

        // Then
        $this->expectExceptionObject(IngredientException::notFound('persil-u'));
        $this->adapter([])->remember('user-id', 'ciboulette', 'persil-u');
    }
}

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
    private SpokenIngredientCandidatesInMemoryLoader $candidatesLoader;
    private SpokenPhraseMemoryInMemoryLoader $memory;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
    }

    /**
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     */
    private function adapter(array $candidates, array $remembered = []): SpokenIngredientResolverAdapter
    {
        $this->candidatesLoader = new SpokenIngredientCandidatesInMemoryLoader($candidates);
        $this->memory = new SpokenPhraseMemoryInMemoryLoader($remembered);

        return new SpokenIngredientResolverAdapter($this->candidatesLoader, $this->memory, $this->bus);
    }

    public function testItLoadsTheCandidatesOfTheUserAndMapsTheChoiceToTheSharedDto(): void
    {
        // Given : un nom identique à la phrase, seul à lui ressembler
        $adapter = $this->adapter([
            new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 4),
            new SpokenCandidate('farine', 'farine', 'farine', 'épicerie', 0),
        ]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'des tomates')->ingredient;

        // Then
        $this->assertEquals(new ResolvedIngredient('tomate', 'tomate', 'fruits et légumes', 'sure'), $resolved);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
    }

    public function testTheConfidenceIsGivenAsItsStringValue(): void
    {
        // Given : deux ingrédients indiscernables (même nom, même rayon), le classeur n'est pas sûr et une question ne les départagerait pas
        $adapter = $this->adapter([
            new SpokenCandidate('tomate-a', 'tomate', 'tomate', 'fruits et légumes', 2, 'fruit-et-legume'),
            new SpokenCandidate('tomate-b', 'tomate', 'tomate', 'fruits et légumes', 2, 'fruit-et-legume'),
        ]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'tomate')->ingredient;

        // Then
        $this->assertSame('unsure', $resolved?->confidence);
    }

    public function testTwoIngredientsOfTheSameNameInTwoDepartmentsAreReturnedToBeToldApart(): void
    {
        // Given : « tomate » en fruit et légume et en surgelé, aucune habitude qui tranche
        $adapter = $this->adapter([
            new SpokenCandidate('tomate-fl', 'tomate', 'Tomate', 'Fruit & légume', 0, 'fruit-et-legume'),
            new SpokenCandidate('tomate-sg', 'tomate', 'Tomate', 'Surgelé', 0, 'surgele'),
            new SpokenCandidate('farine', 'farine', 'Farine', 'Épicerie', 0, 'epicerie'),
        ]);

        // When
        $resolution = $adapter->resolveOrSuggest('user-id', 'des tomates');

        // Then : rien n'est choisi, ni proposé comme inconnu : les deux ex æquo, avec leur rayon ; une seule lecture
        $this->assertNull($resolution->ingredient);
        $this->assertSame([], $resolution->suggestions);
        $this->assertEquals([
            new IngredientSuggestion('tomate-fl', 'Tomate', 'Fruit & légume', 'fruit-et-legume'),
            new IngredientSuggestion('tomate-sg', 'Tomate', 'Surgelé', 'surgele'),
        ], $resolution->ties);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
    }

    public function testTheHabitsStillDecideWithoutAQuestion(): void
    {
        // Given : « tomate » acheté 50 fois en fruit et légume, jamais en surgelé
        $adapter = $this->adapter([
            new SpokenCandidate('tomate-fl', 'tomate', 'Tomate', 'Fruit & légume', 50, 'fruit-et-legume'),
            new SpokenCandidate('tomate-sg', 'tomate', 'Tomate', 'Surgelé', 0, 'surgele'),
        ]);

        // When
        $resolution = $adapter->resolveOrSuggest('user-id', 'tomate');

        // Then
        $this->assertSame(['tomate-fl', 'probable'], [$resolution->ingredient?->slug, $resolution->ingredient?->confidence]);
        $this->assertSame([], $resolution->ties);
    }

    public function testASingleIngredientOrARememberedChoiceIsNeverAQuestion(): void
    {
        // Given : deux « tomate » mais la phrase a déjà été départagée
        $candidates = [
            new SpokenCandidate('tomate-fl', 'tomate', 'Tomate', 'Fruit & légume', 0, 'fruit-et-legume'),
            new SpokenCandidate('tomate-sg', 'tomate', 'Tomate', 'Surgelé', 0, 'surgele'),
        ];
        $remembered = $this->adapter($candidates, ['tomate' => 'tomate-sg'])->resolveOrSuggest('user-id', 'tomate');
        $single = $this->adapter([$candidates[0]])->resolveOrSuggest('user-id', 'tomate');

        // Then
        $this->assertSame(['tomate-sg', 'sure', []], [$remembered->ingredient?->slug, $remembered->ingredient?->confidence, $remembered->ties]);
        $this->assertSame(['tomate-fl', 'sure', []], [$single->ingredient?->slug, $single->ingredient?->confidence, $single->ties]);
    }

    public function testAnAccountWithoutIngredientGetsNullAndTheMemoryIsNotRead(): void
    {
        // Given
        $adapter = $this->adapter([], ['pain' => 'pain']);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'du pain')->ingredient;

        // Then
        $this->assertNull($resolved);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
        $this->assertSame([], $this->memory->calls, 'aucun ingrédient : pas de requête pour la mémoire');
    }

    public function testAPhraseThatResemblesNoIngredientGetsNull(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('pain', 'pain', 'pain', 'boulangerie', 9)]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'xyz')->ingredient;

        // Then
        $this->assertNull($resolved);
    }

    public function testTheMemoryOfThePhraseIsReadBeforeTheRankingAndWins(): void
    {
        // Given : « ciboulette » ne ressemble à rien, mais l'utilisateur a dit que c'était le persil ; la clé est la phrase normalisée
        $adapter = $this->adapter(
            [new SpokenCandidate('persil-u', 'persil', 'persil', 'fruits et légumes', 0), new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)],
            ['ciboulette' => 'persil-u'],
        );

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'de la ciboulette')->ingredient;

        // Then
        $this->assertEquals(new ResolvedIngredient('persil-u', 'persil', 'fruits et légumes', 'sure'), $resolved);
        $this->assertSame([['user-id', 'ciboulette']], $this->memory->calls);
    }

    public function testAMemoryThatNamesAnIngredientThatNoLongerExistsIsIgnored(): void
    {
        // Given : la mémoire désigne un ingrédient supprimé depuis ; le classement reprend la main
        $adapter = $this->adapter([new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)], ['tomate' => 'supprime-u']);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'tomate')->ingredient;

        // Then
        $this->assertSame('tomate-u', $resolved?->slug);
    }

    public function testAPhraseWithoutAnyMeaningfulWordDoesNotReadTheMemory(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('tomate-u', 'tomate', 'tomate', 'fruits et légumes', 0)]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'de la')->ingredient;

        // Then
        $this->assertNull($resolved);
        $this->assertSame([], $this->memory->calls);
    }

    public function testItSuggestsTheClosestIngredientsOfTheUserAsSharedDtos(): void
    {
        // Given
        $adapter = $this->adapter([
            new SpokenCandidate('sirop-u', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'boisson', 0),
            new SpokenCandidate('farine-u', 'farine', 'Farine', 'épicerie', 0),
        ]);

        // When
        $suggestions = $adapter->resolveOrSuggest('user-id', 'sirop de menthe')->suggestions;

        // Then
        $this->assertEquals([new IngredientSuggestion('sirop-u', 'Sirop fruit rouge', 'boisson')], $suggestions);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
    }

    public function testNothingCloseMeansNoSuggestion(): void
    {
        // Given
        $adapter = $this->adapter([new SpokenCandidate('melon-u', 'melon', 'Melon', 'fruits', 0)]);

        // Then
        $this->assertSame([], $adapter->resolveOrSuggest('user-id', 'zébulon')->suggestions);
        $this->assertSame([], $this->adapter([])->resolveOrSuggest('user-id', 'zébulon')->suggestions);
    }

    public function testTheCandidatesAreReadOnlyOnceToChooseOrToPropose(): void
    {
        // Given : rien ne se choisit tout seul, des propositions sont faites
        $adapter = $this->adapter([new SpokenCandidate('sirop-u', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'boisson', 0)]);

        // When
        $resolution = $adapter->resolveOrSuggest('user-id', 'sirop de menthe');

        // Then : une seule lecture des ingrédients, une seule de la mémoire
        $this->assertNull($resolution->ingredient);
        $this->assertCount(1, $resolution->suggestions);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
        $this->assertSame([['user-id', 'sirop-menthe']], $this->memory->calls);
    }

    public function testAProductThatDoesNotExistIsNotGuessedFromAResemblingNameButAFaultIsForgiven(): void
    {
        // Given : « ciboulette » ressemble à *coquilette* (0,907), qui n'est pas le même produit
        $without = $this->adapter([new SpokenCandidate('coquilette-u', 'coquilette', 'Coquilette', 'épicerie', 9)]);
        $with = $this->adapter([new SpokenCandidate('ciboulette-u', 'ciboulette', 'Ciboulette', 'fruits et légumes', 0), new SpokenCandidate('coquilette-u', 'coquilette', 'Coquilette', 'épicerie', 9)]);

        // Then : on demande ; avec *ciboulette* présente, « ciboulete » est une faute de frappe
        $this->assertEquals(\Repas\Shared\Application\Dto\SpokenResolution::unknown(), $without->resolveOrSuggest('user-id', 'ciboulette'));
        $this->assertSame('ciboulette-u', $with->resolveOrSuggest('user-id', 'ciboulete')->ingredient?->slug);
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

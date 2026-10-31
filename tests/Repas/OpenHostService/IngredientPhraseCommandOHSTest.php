<?php

namespace Repas\Tests\Repas\OpenHostService;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use DateTimeImmutable;
use Repas\Repas\Application\OpenHostService\IngredientPhraseCommandOHS;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\IngredientPhraseResolution;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\IngredientPhraseResolver;
use Repas\Tests\Helper\InMemoryRepository\IngredientPhraseCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenPhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'Open Host Service seulement (dépôts en mémoire et bus simulé) : il charge les candidats de l'utilisateur, lit la mémoire de phrase AVANT le
 * classement, laisse le classeur choisir ou proposer et rend des modèles de `Repas`. Le classement lui-même est prouvé par
 * `SpokenIngredientRankerTest`, la lecture des habitudes et de la mémoire par les tests des Loaders et des Repositories, le parcours complet par
 * `IngredientPhraseCommandOHSFlowTest`.
 */
class IngredientPhraseCommandOHSTest extends TestCase
{
    private MockCommandBus $bus;
    private IngredientPhraseCandidatesInMemoryRepository $candidatesLoader;
    private SpokenPhraseChoiceInMemoryRepository $memory;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
    }

    /**
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     */
    private function adapter(array $candidates, array $remembered = []): IngredientPhraseCommandOHS
    {
        $this->candidatesLoader = new IngredientPhraseCandidatesInMemoryRepository($candidates);
        $this->memory = new SpokenPhraseChoiceInMemoryRepository();
        foreach ($remembered as $key => $slug) {
            $this->memory->remember('user-id', (string) $key, $slug, new DateTimeImmutable('2026-10-25 10:00:00'));
        }
        $this->memory->reads = [];

        return new IngredientPhraseCommandOHS($this->candidatesLoader, $this->memory, new IngredientPhraseResolver(), $this->bus);
    }

    public function testItLoadsTheCandidatesOfTheUserAndRanksThemWithTheirConfidence(): void
    {
        // Given : un nom identique à la phrase, seul à lui ressembler
        $adapter = $this->adapter([
            new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 4),
            new SpokenCandidate('farine', 'farine', 'farine', 'épicerie', 0),
        ]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'des tomates')->ingredient;

        // Then
        $this->assertSame(['tomate', 'tomate', 'fruits et légumes', Confidence::SURE], [$resolved?->slug, $resolved->name, $resolved->departmentName, $resolved->confidence]);
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
    }

    public function testTwoIdenticalIngredientsAreNotSure(): void
    {
        // Given : deux ingrédients indiscernables (même nom, même rayon), le classeur n'est pas sûr et une question ne les départagerait pas
        $adapter = $this->adapter([
            new SpokenCandidate('tomate-a', 'tomate', 'tomate', 'fruits et légumes', 2, 'fruit-et-legume'),
            new SpokenCandidate('tomate-b', 'tomate', 'tomate', 'fruits et légumes', 2, 'fruit-et-legume'),
        ]);

        // When
        $resolved = $adapter->resolveOrSuggest('user-id', 'tomate')->ingredient;

        // Then
        $this->assertSame(Confidence::UNSURE, $resolved?->confidence);
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
        $this->assertSame(
            [['tomate-fl', 'Tomate', 'Fruit & légume', 'fruit-et-legume'], ['tomate-sg', 'Tomate', 'Surgelé', 'surgele']],
            array_map(static fn(RankedIngredient $tie): array => [$tie->slug, $tie->name, $tie->departmentName, $tie->departmentSlug], $resolution->ties),
        );
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
        $this->assertSame(['tomate-fl', 'probable'], [$resolution->ingredient?->slug, $resolution->ingredient?->confidence->value]);
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
        $this->assertSame(['tomate-sg', Confidence::SURE, []], [$remembered->ingredient?->slug, $remembered->ingredient?->confidence, $remembered->ties]);
        $this->assertSame(['tomate-fl', Confidence::SURE, []], [$single->ingredient?->slug, $single->ingredient?->confidence, $single->ties]);
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
        $this->assertSame([], $this->memory->reads, 'aucun ingrédient : pas de requête pour la mémoire');
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
        $this->assertSame(['persil-u', 'persil', 'fruits et légumes', Confidence::SURE], [$resolved?->slug, $resolved->name, $resolved->departmentName, $resolved->confidence]);
        $this->assertSame([['user-id', 'ciboulette']], $this->memory->reads);
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
        $this->assertSame([], $this->memory->reads);
    }

    public function testItSuggestsTheClosestIngredientsOfTheUser(): void
    {
        // Given
        $adapter = $this->adapter([
            new SpokenCandidate('sirop-u', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'boisson', 0),
            new SpokenCandidate('farine-u', 'farine', 'Farine', 'épicerie', 0),
        ]);

        // When
        $suggestions = $adapter->resolveOrSuggest('user-id', 'sirop de menthe')->suggestions;

        // Then
        $this->assertSame([['sirop-u', 'Sirop fruit rouge', 'boisson']], array_map(static fn(RankedIngredient $suggestion): array => [$suggestion->slug, $suggestion->name, $suggestion->departmentName], $suggestions));
        $this->assertSame(['user-id'], $this->candidatesLoader->calls);
    }

    public function testTwoIngredientsOfTheSameNameAreProposedOnceAndTheNextNameTakesTheFreeSlot(): void
    {
        // Given : « Sirop fruit rouge » en deux rayons (celui du surgelé est bien plus acheté), « Sirop fraise vanille » et « Sirop cassis noir » ; la
        // phrase est approximative (rien ne se choisit tout seul)
        $adapter = $this->adapter([
            new SpokenCandidate('sirop-epicerie', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'Épicerie', 1, 'epicerie'),
            new SpokenCandidate('sirop-surgele', 'sirop-fruit-rouge', 'Sirop fruit rouge', 'Surgelé', 40, 'surgele'),
            new SpokenCandidate('sirop-cassis', 'sirop-cassis-noir', 'Sirop cassis noir', 'Boisson', 0, 'boisson'),
            new SpokenCandidate('sirop-fraise', 'sirop-fraise-vanille', 'Sirop fraise vanille', 'Boisson', 0, 'boisson'),
        ]);

        // When
        $resolution = $adapter->resolveOrSuggest('user-id', 'sirop de menthe');

        // Then : le nom n'est proposé qu'une fois (le mieux classé : le plus acheté), l'autre rayon n'est pas dit en double, un autre nom prend la place
        $this->assertNull($resolution->ingredient);
        $this->assertSame(['sirop-surgele', 'sirop-fraise'], array_map(static fn(RankedIngredient $suggestion): string => $suggestion->slug, $resolution->suggestions));
        $this->assertSame(['Sirop fruit rouge', 'Sirop fraise vanille'], array_map(static fn(RankedIngredient $suggestion): string => $suggestion->name, $resolution->suggestions));
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
        $this->assertSame([['user-id', 'sirop-menthe']], $this->memory->reads);
    }

    public function testAProductThatDoesNotExistIsNotGuessedFromAResemblingNameButAFaultIsForgiven(): void
    {
        // Given : « ciboulette » ressemble à *coquilette* (0,907), qui n'est pas le même produit
        $without = $this->adapter([new SpokenCandidate('coquilette-u', 'coquilette', 'Coquilette', 'épicerie', 9)]);
        $with = $this->adapter([new SpokenCandidate('ciboulette-u', 'ciboulette', 'Ciboulette', 'fruits et légumes', 0), new SpokenCandidate('coquilette-u', 'coquilette', 'Coquilette', 'épicerie', 9)]);

        // Then : on demande ; avec *ciboulette* présente, « ciboulete » est une faute de frappe
        $this->assertEquals(IngredientPhraseResolution::unknown(), $without->resolveOrSuggest('user-id', 'ciboulette'));
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

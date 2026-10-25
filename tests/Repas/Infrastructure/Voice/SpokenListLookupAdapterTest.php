<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Repas\Infrastructure\Voice\SpokenListLookupAdapter;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Tests\Helper\InMemoryLoader\ActiveShoppingListLinesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenIngredientCandidatesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenPhraseMemoryInMemoryLoader;

/**
 * L'adaptateur seulement (ports de lecture simulés) : il lit la liste active, laisse le classeur de l'ajout désigner l'ingrédient, puis ne garde que
 * ce qui est DANS la liste (ADR 0029 décision 12, VOX-304). Le classement est prouvé par `SpokenIngredientRankerTest`, la lecture SQL par
 * `ActiveShoppingListLinesPostgreSQLLoaderTest` et `SpokenListLookupFlowTest`.
 */
class SpokenListLookupAdapterTest extends TestCase
{
    private ActiveShoppingListLinesInMemoryLoader $linesLoader;
    private SpokenIngredientCandidatesInMemoryLoader $candidatesLoader;

    /**
     * @param ?list<ListedLine>     $lines      les lignes de la liste active (`null` : aucune)
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     */
    private function adapter(?array $lines, array $candidates, array $remembered = []): SpokenListLookupAdapter
    {
        $this->linesLoader = new ActiveShoppingListLinesInMemoryLoader($lines);
        $this->candidatesLoader = new SpokenIngredientCandidatesInMemoryLoader($candidates);

        return new SpokenListLookupAdapter($this->linesLoader, $this->candidatesLoader, new SpokenPhraseMemoryInMemoryLoader($remembered));
    }

    private static function line(string $slug, string $name, string $departmentSlug, string $departmentName, float $quantity = 1.0): ListedLine
    {
        return new ListedLine($slug, $name, $departmentSlug, $departmentName, $quantity, 'unite', 'unité');
    }

    public function testWithoutAnActiveListNothingElseIsRead(): void
    {
        // Given
        $adapter = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')]);

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : pas de liste, et les ingrédients de l'utilisateur ne sont même pas chargés
        $this->assertEquals(SpokenListLookupResult::noList(), $result);
        $this->assertSame(['user-id'], $this->linesLoader->calls);
        $this->assertSame([], $this->candidatesLoader->calls);
    }

    public function testTheLineOfTheIngredientTheClassifierChoosesIsFound(): void
    {
        // Given
        $tomato = self::line('tomate', 'tomate', 'fruit-et-legume', 'fruits et légumes', 3.0);
        $adapter = $this->adapter(
            [$tomato, self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'), new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then
        $this->assertEquals(SpokenListLookupResult::found($tomato), $result);
        $this->assertTrue($result->hasList);
    }

    public function testAnIngredientOfTheUserThatIsNotInTheListIsAbsentWithItsName(): void
    {
        // Given : la liste a de la farine, l'utilisateur demande des tomates qu'il connaît
        $adapter = $this->adapter(
            [self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes'), new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : « pas de tomate dans la liste », avec le nom de l'ingrédient reconnu
        $this->assertEquals(SpokenListLookupResult::notInList('tomate'), $result);
    }

    public function testAPhraseThatMatchesNoIngredientIsAbsentWithoutAName(): void
    {
        // Given
        $adapter = $this->adapter(
            [self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')],
        );

        // When
        $result = $adapter->find('user-id', 'de la ciboulette');

        // Then : jamais de proposition, jamais de création
        $this->assertEquals(SpokenListLookupResult::notInList(null), $result);
    }

    public function testAUserWithoutAnyIngredientHasNothingInTheList(): void
    {
        // Given : une liste active, aucun ingrédient connu
        $adapter = $this->adapter([], []);

        // When
        $result = $adapter->find('user-id', 'du pain');

        // Then
        $this->assertEquals(SpokenListLookupResult::notInList(null), $result);
    }

    public function testWhatTheUserChoseForAPhraseWinsLikeForTheAdd(): void
    {
        // Given : « patates » a été retenu pour la pomme de terre, qui est dans la liste
        $potato = self::line('pomme-de-terre', 'pomme de terre', 'fruit-et-legume', 'fruits et légumes', 2.0);
        $adapter = $this->adapter(
            [$potato],
            [new SpokenCandidate('pomme-de-terre', 'pomme-de-terre', 'pomme de terre', 'fruits et légumes'), new SpokenCandidate('pates', 'pates', 'pâtes', 'épicerie')],
            [SpokenIngredientRanker::phraseKey('des patates') => 'pomme-de-terre'],
        );

        // When
        $result = $adapter->find('user-id', 'des patates');

        // Then
        $this->assertEquals(SpokenListLookupResult::found($potato), $result);
    }

    public function testOfTwoIngredientsThatTieOnlyTheOneInTheListIsKept(): void
    {
        // Given : « tomate » en fruit et légume ET en surgelé, seule la surgelée est dans la liste
        $frozen = self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 2.0);
        $adapter = $this->adapter(
            [$frozen],
            [
                new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'),
                new SpokenCandidate('tomate-surgelee', 'tomate', 'tomate', 'surgelé', 0, 'surgele'),
            ],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : pas de question, c'est celle de la liste
        $this->assertEquals(SpokenListLookupResult::found($frozen), $result);
    }

    public function testOfTwoIngredientsThatTieBothInTheListAreReturnedToBeTold(): void
    {
        // Given : les deux tomates sont dans la liste
        $fresh = self::line('tomate', 'tomate', 'fruit-et-legume', 'fruits et légumes', 2.0);
        $frozen = self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 1.0);
        $adapter = $this->adapter(
            [$frozen, $fresh],
            [
                new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'),
                new SpokenCandidate('tomate-surgelee', 'tomate', 'tomate', 'surgelé', 0, 'surgele'),
            ],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : les deux lignes, à départager par le rayon
        $this->assertTrue($result->isTied());
        $this->assertEqualsCanonicalizing([$fresh, $frozen], $result->lines);
    }

    public function testOfTwoIngredientsThatTieNeitherInTheListIsAbsentWithItsName(): void
    {
        // Given : aucune des deux tomates n'est dans la liste
        $adapter = $this->adapter(
            [self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [
                new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'),
                new SpokenCandidate('tomate-surgelee', 'tomate', 'tomate', 'surgelé', 0, 'surgele'),
                new SpokenCandidate('farine', 'farine', 'farine', 'épicerie'),
            ],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then
        $this->assertFalse($result->isTied());
        $this->assertSame([], $result->lines);
        $this->assertSame('tomate', $result->absentName);
    }

    public function testNothingIsWritten(): void
    {
        // Given : l'adaptateur n'a ni bus ni dépôt dans son constructeur
        $constructor = new \ReflectionMethod(SpokenListLookupAdapter::class, '__construct');

        // Then : seulement des ports de lecture
        $types = array_map(static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor->getParameters());
        $this->assertSame([
            'Repas\Repas\Application\Loader\ActiveShoppingListLines',
            'Repas\Repas\Application\Loader\SpokenIngredientCandidates',
            'Repas\Repas\Application\Loader\SpokenPhraseMemory',
        ], $types);
    }
}

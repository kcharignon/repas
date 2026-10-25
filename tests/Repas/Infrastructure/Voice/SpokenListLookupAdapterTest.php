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
     * @param bool                  $hasSentList l'utilisateur a une liste envoyée au drive (sans liste active)
     */
    private function adapter(?array $lines, array $candidates, array $remembered = [], bool $hasSentList = false): SpokenListLookupAdapter
    {
        $this->linesLoader = new ActiveShoppingListLinesInMemoryLoader($lines, $hasSentList);
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

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Revue du 2026-10-25 : habitudes qui tranchent, doublons, liste envoyée
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return list<SpokenCandidate>
     */
    private function twoTomatoes(int $freshUses, int $frozenUses): array
    {
        return [
            new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes', $freshUses, 'fruit-et-legume'),
            new SpokenCandidate('tomate-surgelee', 'tomate', 'tomate', 'surgelé', $frozenUses, 'surgele'),
        ];
    }

    /**
     * @return list<SpokenCandidate>
     */
    private function twoDuplicates(): array
    {
        return [
            new SpokenCandidate('tomate-1', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'),
            new SpokenCandidate('tomate-2', 'tomate', 'tomate', 'fruits et légumes', 0, 'fruit-et-legume'),
        ];
    }

    public function testTheTomatoInTheListIsFoundEvenWhenTheHabitsPickTheOtherOne(): void
    {
        // Given : les habitudes tranchent pour la tomate fraîche (40 usages contre 2, `PROBABLE`), mais seule la surgelée est dans la liste
        $frozen = self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 2.0);
        $adapter = $this->adapter([$frozen], $this->twoTomatoes(40, 2));

        // When : « enlève des tomates » / « est-ce qu'il y a des tomates ? »
        $result = $adapter->find('user-id', 'des tomates');

        // Then : la ligne de la liste, pas « pas dans ta liste »
        $this->assertEquals(SpokenListLookupResult::found($frozen), $result);
    }

    public function testBothTomatoesInTheListAreReturnedToBeToldApartWhateverTheHabits(): void
    {
        // Given : les habitudes tranchent, mais les deux sont dans la liste
        $fresh = self::line('tomate', 'tomate', 'fruit-et-legume', 'fruits et légumes', 2.0);
        $frozen = self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 1.0);
        $adapter = $this->adapter([$frozen, $fresh], $this->twoTomatoes(40, 2));

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : les deux lignes, la plus utilisée d'abord
        $this->assertTrue($result->isTied());
        $this->assertEquals([$fresh, $frozen], $result->lines);
    }

    public function testNeitherTomatoInTheListIsAbsentWithTheNameTheClassifierWouldHaveChosen(): void
    {
        // Given
        $adapter = $this->adapter([self::line('farine', 'farine', 'epicerie', 'épicerie')], [...$this->twoTomatoes(40, 2), new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')]);

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then
        $this->assertEquals(SpokenListLookupResult::notInList('tomate'), $result);
    }

    public function testTheDuplicateInTheListIsFoundEvenWhenTheClassifierChoosesTheOtherDuplicate(): void
    {
        // Given : deux « tomate » de même nom ET de même rayon (non fusionnées) ; `ties()` ne les départage pas, `rank()` choisit la première
        $adapter = $this->adapter([self::line('tomate-2', 'tomate', 'fruit-et-legume', 'fruits et légumes', 3.0)], $this->twoDuplicates());

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : celle de la liste, pas « pas dans ta liste »
        $this->assertSame(['tomate-2'], array_map(static fn(ListedLine $line): string => $line->slug, $result->lines));
        $this->assertFalse($result->isTied());
    }

    public function testTwoDuplicatesBothInTheListKeepTheOneWithTheGreatestQuantityAndAskNothing(): void
    {
        // Given : les deux doublons sont dans la liste (2 et 5) : une question ne les distinguerait pas
        $small = self::line('tomate-1', 'tomate', 'fruit-et-legume', 'fruits et légumes', 2.0);
        $big = self::line('tomate-2', 'tomate', 'fruit-et-legume', 'fruits et légumes', 5.0);
        $adapter = $this->adapter([$small, $big], $this->twoDuplicates());

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : la plus grande quantité, aucune question
        $this->assertEquals(SpokenListLookupResult::found($big), $result);
    }

    public function testDuplicatesAndAnotherDepartmentAreKeptOnePerNameAndDepartment(): void
    {
        // Given : deux doublons en fruit et légume (1 et 4) et une surgelée (2), toutes dans la liste
        $adapter = $this->adapter(
            [
                self::line('tomate-1', 'tomate', 'fruit-et-legume', 'fruits et légumes', 1.0),
                self::line('tomate-2', 'tomate', 'fruit-et-legume', 'fruits et légumes', 4.0),
                self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 2.0),
            ],
            [...$this->twoDuplicates(), new SpokenCandidate('tomate-surgelee', 'tomate', 'tomate', 'surgelé', 0, 'surgele')],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : deux rayons à départager, le doublon de plus grande quantité représente le sien
        $this->assertTrue($result->isTied());
        $this->assertEqualsCanonicalizing(['tomate-2', 'tomate-surgelee'], array_map(static fn(ListedLine $line): string => $line->slug, $result->lines));
    }

    public function testAListSentToTheDriveIsSaidApartFromNoList(): void
    {
        // Given : aucune liste active, une liste envoyée, puis aucune liste du tout
        $sent = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')], hasSentList: true);
        $sentLoader = $this->linesLoader;
        $none = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')]);

        // When
        $sentResult = $sent->find('user-id', 'des tomates');
        $noneResult = $none->find('user-id', 'des tomates');

        // Then : la liste envoyée n'est jamais lue ; seule la question « en a-t-il une ? » est posée
        $this->assertEquals(SpokenListLookupResult::sentList(), $sentResult);
        $this->assertTrue($sentResult->listSent);
        $this->assertFalse($sentResult->hasList);
        $this->assertSame(['user-id'], $sentLoader->sentCalls);
        $this->assertEquals(SpokenListLookupResult::noList(), $noneResult);
        $this->assertFalse($noneResult->listSent);
    }

    public function testTheSentListIsNotLookedForWhenAnActiveListExists(): void
    {
        // Given
        $adapter = $this->adapter([self::line('farine', 'farine', 'epicerie', 'épicerie')], [new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')], hasSentList: true);

        // When
        $result = $adapter->find('user-id', 'de la farine');

        // Then : une liste active gagne, la question « envoyée ? » n'est pas posée
        $this->assertTrue($result->hasList);
        $this->assertFalse($result->listSent);
        $this->assertSame([], $this->linesLoader->sentCalls);
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

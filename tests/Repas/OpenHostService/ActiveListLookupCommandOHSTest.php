<?php

namespace Repas\Tests\Repas\OpenHostService;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupCommandOHS;
use Repas\Repas\Application\OpenHostService\ActiveListMealLookupCommandOHS;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\ActiveListLineMatch;
use Repas\Repas\Domain\Model\ActiveListMeal;
use Repas\Repas\Domain\Model\ActiveListMealMatch;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\ActiveListLookup;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Tests\Helper\InMemoryRepository\ActiveShoppingListContentsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPhraseCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenPhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenRecipePhraseChoiceInMemoryRepository;

/**
 * Les Open Host Services d'écriture de la liste active seulement (dépôts en mémoire) : ils lisent la liste active, laissent le classeur de l'ajout
 * désigner l'ingrédient, puis ne gardent que ce qui est DANS la liste (ADR 0029 décision 12, VOX-304). Le classement est prouvé par
 * `SpokenIngredientRankerTest`, la lecture SQL par `ActiveShoppingListLinesPostgreSQLLoaderTest` et `ActiveListLineLookupCommandOHSFlowTest`. Les repas
 * planifiés (VOX-306) se cherchent de même, parmi les seules recettes de la liste active, avec la mémoire de phrase des recettes. La lecture seule
 * (`ActiveListLineLookupQueryOHS`) rend la même chose : `ActiveListLineLookupQueryOHSTest`.
 */
class ActiveListLookupCommandOHSTest extends TestCase
{
    private ActiveShoppingListContentsInMemoryRepository $contents;
    private IngredientPhraseCandidatesInMemoryRepository $candidatesRepository;
    private SpokenRecipePhraseChoiceInMemoryRepository $recipeMemory;

    /**
     * @param ?list<ActiveListLine> $lines      les lignes de la liste active (`null` : aucune)
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     * @param bool                  $hasSentList l'utilisateur a une liste envoyée au drive (sans liste active)
     * @param ?list<ActiveListMeal> $meals       les repas de la liste active (`null` : aucune liste active)
     * @param array<string, string> $rememberedRecipes clé de phrase => identifiant de recette
     */
    private function adapter(?array $lines, array $candidates, array $remembered = [], bool $hasSentList = false, ?array $meals = [], array $rememberedRecipes = []): ActiveListLookupHarness
    {
        $this->contents = new ActiveShoppingListContentsInMemoryRepository($lines, $hasSentList, $meals);
        $this->candidatesRepository = new IngredientPhraseCandidatesInMemoryRepository($candidates);
        $memory = new SpokenPhraseChoiceInMemoryRepository();
        foreach ($remembered as $key => $slug) {
            $memory->remember('user-id', (string) $key, $slug, new DateTimeImmutable('2026-10-25 10:00:00'));
        }
        $this->recipeMemory = new SpokenRecipePhraseChoiceInMemoryRepository();
        foreach ($rememberedRecipes as $key => $recipeId) {
            $this->recipeMemory->remember('user-id', (string) $key, $recipeId, new DateTimeImmutable('2026-10-25 10:00:00'));
        }
        $this->recipeMemory->reads = [];

        $lookup = new ActiveListLookup();
        $lineOhs = new ActiveListLineLookupCommandOHS($this->contents, $this->candidatesRepository, $memory, $lookup);
        $mealOhs = new ActiveListMealLookupCommandOHS($this->contents, $this->recipeMemory, $lookup);

        return new ActiveListLookupHarness($lineOhs, $mealOhs);
    }


    private static function line(string $slug, string $name, string $departmentSlug, string $departmentName, float $quantity = 1.0): ActiveListLine
    {
        return new ActiveListLine($slug, $name, $departmentSlug, $departmentName, $quantity, 'unite', 'unité');
    }

    public function testWithoutAnActiveListNothingElseIsRead(): void
    {
        // Given
        $adapter = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')]);

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : pas de liste, et les ingrédients de l'utilisateur ne sont même pas chargés
        $this->assertEquals(ActiveListLineMatch::noList(), $result);
        $this->assertSame(['user-id'], $this->contents->calls);
        $this->assertSame([], $this->candidatesRepository->calls);
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
        $this->assertEquals(ActiveListLineMatch::found($tomato), $result);
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
        $this->assertEquals(ActiveListLineMatch::notInList('tomate'), $result);
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
        $this->assertEquals(ActiveListLineMatch::notInList(null), $result);
    }

    public function testAUserWithoutAnyIngredientHasNothingInTheList(): void
    {
        // Given : une liste active, aucun ingrédient connu
        $adapter = $this->adapter([], []);

        // When
        $result = $adapter->find('user-id', 'du pain');

        // Then
        $this->assertEquals(ActiveListLineMatch::notInList(null), $result);
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
        $this->assertEquals(ActiveListLineMatch::found($potato), $result);
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
        $this->assertEquals(ActiveListLineMatch::found($frozen), $result);
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
        $this->assertEquals(ActiveListLineMatch::found($frozen), $result);
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
        $this->assertEquals(ActiveListLineMatch::notInList('tomate'), $result);
    }

    public function testTheDuplicateInTheListIsFoundEvenWhenTheClassifierChoosesTheOtherDuplicate(): void
    {
        // Given : deux « tomate » de même nom ET de même rayon (non fusionnées) ; `ties()` ne les départage pas, `rank()` choisit la première
        $adapter = $this->adapter([self::line('tomate-2', 'tomate', 'fruit-et-legume', 'fruits et légumes', 3.0)], $this->twoDuplicates());

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : celle de la liste, pas « pas dans ta liste »
        $this->assertSame(['tomate-2'], array_map(static fn(ActiveListLine $line): string => $line->slug, $result->lines));
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
        $this->assertEquals(ActiveListLineMatch::found($big), $result);
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
        $this->assertEqualsCanonicalizing(['tomate-2', 'tomate-surgelee'], array_map(static fn(ActiveListLine $line): string => $line->slug, $result->lines));
    }

    public function testAListSentToTheDriveIsSaidApartFromNoList(): void
    {
        // Given : aucune liste active, une liste envoyée, puis aucune liste du tout
        $sent = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')], hasSentList: true);
        $sentLoader = $this->contents;
        $none = $this->adapter(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')]);

        // When
        $sentResult = $sent->find('user-id', 'des tomates');
        $noneResult = $none->find('user-id', 'des tomates');

        // Then : la liste envoyée n'est jamais lue ; seule la question « en a-t-il une ? » est posée
        $this->assertEquals(ActiveListLineMatch::sentList(), $sentResult);
        $this->assertTrue($sentResult->listSent);
        $this->assertFalse($sentResult->hasList);
        $this->assertSame(['user-id'], $sentLoader->sentCalls);
        $this->assertEquals(ActiveListLineMatch::noList(), $noneResult);
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
        $this->assertSame([], $this->contents->sentCalls);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Une phrase qui ne désigne aucun ingrédient : les lignes de la liste qui lui ressemblent (VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testAPhraseThatMatchesNoIngredientGetsTheLinesOfTheListThatLookLikeIt(): void
    {
        // Given : la liste porte deux pâtes ; la phrase dite ne désigne aucun ingrédient de l'utilisateur
        $brisee = self::line('pate-brisee', 'Pâte brisée', 'frais', 'frais');
        $sablee = self::line('pate-sablee', 'Pâte sablée', 'frais', 'frais');
        $adapter = $this->adapter(
            [$brisee, $sablee],
            [new SpokenCandidate('pate-brisee', 'pate-brisee', 'Pâte brisée', 'frais'), new SpokenCandidate('pate-sablee', 'pate-sablee', 'Pâte sablée', 'frais')],
        );

        // When
        $result = $adapter->find('user-id', 'pâtes à pizza');

        // Then : rien dans la liste, pas d'ingrédient reconnu, et les deux lignes proches sont proposées
        $this->assertEquals(ActiveListLineMatch::notInList(null, [$brisee, $sablee]), $result);
        $this->assertNull($result->absentName);
    }

    public function testOnlyTheIngredientsInTheListAreSuggestedAndAnAbsentOneIsNot(): void
    {
        // Given : deux pâtes connues, une seule dans la liste
        $brisee = self::line('pate-brisee', 'Pâte brisée', 'frais', 'frais');
        $adapter = $this->adapter(
            [$brisee],
            [new SpokenCandidate('pate-brisee', 'pate-brisee', 'Pâte brisée', 'frais'), new SpokenCandidate('pate-sablee', 'pate-sablee', 'Pâte sablée', 'frais')],
        );

        // When
        $result = $adapter->find('user-id', 'pâtes à pizza');

        // Then : la pâte qui n'est pas dans la liste n'est pas proposée
        $this->assertEquals([$brisee], $result->suggestions);
    }

    public function testAnIngredientKnownButAbsentGetsNoSuggestion(): void
    {
        // Given : « tomate » est connue, absente de la liste
        $adapter = $this->adapter(
            [self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes'), new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')],
        );

        // When
        $result = $adapter->find('user-id', 'des tomates');

        // Then : la réponse est définitive (« pas de tomate dans ta liste »)
        $this->assertSame([], $result->suggestions);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les repas planifiés (VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testWithoutAnActiveListNoMealIsFoundAndNothingElseIsRead(): void
    {
        // Given
        $adapter = $this->adapter(null, [], meals: null);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then
        $this->assertEquals(ActiveListMealMatch::noList(), $result);
        $this->assertSame(['user-id'], $this->contents->mealCalls);
        $this->assertSame([], $this->contents->calls);
        $this->assertSame([], $this->candidatesRepository->calls);
    }

    public function testAListSentToTheDriveIsSaidApartForMealsToo(): void
    {
        // Given
        $adapter = $this->adapter(null, [], hasSentList: true, meals: null);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then : jamais lue ni modifiée
        $this->assertEquals(ActiveListMealMatch::sentList(), $result);
        $this->assertTrue($result->listSent);
    }

    public function testThePlannedMealThePhraseNamesIsFound(): void
    {
        // Given
        $hachis = new ActiveListMeal('recipe-1', 'Hachis parmentier', 4);
        $adapter = $this->adapter([], [], meals: [$hachis, new ActiveListMeal('recipe-2', 'Gratin dauphinois', 2)]);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then
        $this->assertEquals(ActiveListMealMatch::found($hachis), $result);
    }

    public function testOnlyThePlannedMealsAreSearchedNeverTheOtherRecipesOfTheUser(): void
    {
        // Given : la liste ne planifie que le gratin ; le hachis existe peut-être parmi les recettes, il n'est pas dans la liste
        $gratin = new ActiveListMeal('recipe-2', 'Gratin dauphinois', 2);
        $adapter = $this->adapter([], [], meals: [$gratin]);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then : pas dans la liste, rien d'assez proche
        $this->assertEquals(ActiveListMealMatch::notPlanned(), $result);
    }

    public function testAnActiveListWithoutMealsHasNoMeal(): void
    {
        // Given
        $adapter = $this->adapter([], [], meals: []);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then : une liste active (le dialogue peut continuer), mais aucun repas
        $this->assertEquals(ActiveListMealMatch::notPlanned(), $result);
        $this->assertTrue($result->hasList);
    }

    public function testTheClosestPlannedMealsAreProposedWhenNoneIsSure(): void
    {
        // Given : deux pâtes planifiées, la phrase n'en désigne aucune
        $brisee = new ActiveListMeal('recipe-1', 'Pâte brisée', 4);
        $sablee = new ActiveListMeal('recipe-2', 'Pâte sablée', 2);
        $adapter = $this->adapter([], [], meals: [$brisee, $sablee]);

        // When
        $result = $adapter->findMeal('user-id', 'pâtes à pizza');

        // Then : proposées, jamais devinées
        $this->assertEquals(ActiveListMealMatch::notPlanned([$brisee, $sablee]), $result);
        $this->assertNull($result->meal);
    }

    public function testAPhraseTheUserRememberedForARecipeWinsWhenItIsPlanned(): void
    {
        // Given : « hachis » a été retenu pour la recette 1, planifiée
        $hachis = new ActiveListMeal('recipe-1', 'Hachis parmentier', 4);
        $adapter = $this->adapter([], [], meals: [$hachis, new ActiveListMeal('recipe-2', 'Gratin dauphinois', 2)], rememberedRecipes: [SpokenIngredientRanker::phraseKey('hachis') => 'recipe-1']);

        // When
        $result = $adapter->findMeal('user-id', 'hachis');

        // Then
        $this->assertEquals(ActiveListMealMatch::found($hachis), $result);
        $this->assertSame([['user-id', SpokenIngredientRanker::phraseKey('hachis')]], $this->recipeMemory->reads);
    }

    public function testAPhraseRememberedForARecipeThatIsNotPlannedIsNotBelieved(): void
    {
        // Given : « hachis » a été retenu pour la recette 3, qui n'est pas dans la liste
        $gratin = new ActiveListMeal('recipe-2', 'Gratin dauphinois', 2);
        $adapter = $this->adapter([], [], meals: [$gratin], rememberedRecipes: [SpokenIngredientRanker::phraseKey('hachis') => 'recipe-3']);

        // When
        $result = $adapter->findMeal('user-id', 'hachis');

        // Then : la mémoire ne fait pas trouver ce qui n'est pas planifié
        $this->assertNull($result->meal);
    }

    public function testTwoPlannedMealsWithTheSameNameAreProposedNeverGuessed(): void
    {
        // Given : deux recettes de même nom (une copie) planifiées, pour 4 et pour 2 personnes
        $four = new ActiveListMeal('recipe-1', 'Hachis parmentier', 4);
        $two = new ActiveListMeal('recipe-2', 'Hachis parmentier', 2);
        $adapter = $this->adapter([], [], meals: [$four, $two, new ActiveListMeal('recipe-3', 'Gratin dauphinois', 4)]);

        // When : « enlève le repas hachis parmentier »
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then : aucun repas n'est choisi au hasard, les deux homonymes sont proposés (jamais le gratin)
        $this->assertNull($result->meal);
        $this->assertEquals([$four, $two], $result->suggestions);
    }

    public function testThreeHomonymsAreProposedWithTheirServingsAndNoMore(): void
    {
        // Given
        $meals = [new ActiveListMeal('r1', 'Hachis', 4), new ActiveListMeal('r2', 'Hachis', 2), new ActiveListMeal('r3', 'Hachis', 6), new ActiveListMeal('r4', 'Hachis', 8)];
        $adapter = $this->adapter([], [], meals: $meals);

        // When
        $result = $adapter->findMeal('user-id', 'hachis');

        // Then : trois au plus, comme toute proposition
        $this->assertNull($result->meal);
        $this->assertCount(3, $result->suggestions);
    }

    public function testAPhraseRememberedForOneOfTwoHomonymsChoosesIt(): void
    {
        // Given : « hachis » a été retenu pour la recette 2, les deux hachis sont planifiés
        $one = new ActiveListMeal('recipe-1', 'Hachis parmentier', 4);
        $two = new ActiveListMeal('recipe-2', 'Hachis parmentier', 2);
        $adapter = $this->adapter([], [], meals: [$one, $two], rememberedRecipes: [SpokenIngredientRanker::phraseKey('hachis') => 'recipe-2']);

        // When
        $result = $adapter->findMeal('user-id', 'hachis');

        // Then : le choix de l'utilisateur passe avant, sans question
        $this->assertEquals(ActiveListMealMatch::found($two), $result);
    }

    public function testTwoDifferentNamesWithoutAHomonymAreStillChosenWhenTheClassifierIsSure(): void
    {
        // Given
        $adapter = $this->adapter([], [], meals: [new ActiveListMeal('recipe-1', 'Hachis parmentier', 4), new ActiveListMeal('recipe-2', 'Hachis de boeuf', 2)]);

        // When
        $result = $adapter->findMeal('user-id', 'hachis parmentier');

        // Then : un nom exact, un seul : pas de question
        $this->assertSame('recipe-1', $result->meal?->recipeId);
    }

    public function testNothingIsWritten(): void
    {
        // Given : les Open Host Services n'ont ni bus ni dépôt d'écriture dans leur constructeur
        $lineTypes = array_map(static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(), new \ReflectionMethod(ActiveListLineLookupCommandOHS::class, '__construct')->getParameters());
        $mealTypes = array_map(static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(), new \ReflectionMethod(ActiveListMealLookupCommandOHS::class, '__construct')->getParameters());

        // Then : seulement des ports de lecture, la mémoire de phrase (lue seulement) et le service de domaine
        $this->assertSame([
            'Repas\Repas\Domain\Interface\ActiveShoppingListContents',
            'Repas\Repas\Domain\Interface\IngredientPhraseCandidates',
            'Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository',
            'Repas\Repas\Domain\Service\ActiveListLookup',
        ], $lineTypes);
        $this->assertSame([
            'Repas\Repas\Domain\Interface\ActiveShoppingListContents',
            'Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository',
            'Repas\Repas\Domain\Service\ActiveListLookup',
        ], $mealTypes);
    }
}

/**
 * Les deux Open Host Services sous les noms que ce test utilise.
 */
final readonly class ActiveListLookupHarness
{
    public function __construct(
        private ActiveListLineLookupCommandOHS $lines,
        private ActiveListMealLookupCommandOHS $meals,
    ) {
    }

    public function find(string $userId, string $phrase): ActiveListLineMatch
    {
        return $this->lines->findLine($userId, $phrase);
    }

    public function findMeal(string $userId, string $phrase): ActiveListMealMatch
    {
        return $this->meals->findMeal($userId, $phrase);
    }
}

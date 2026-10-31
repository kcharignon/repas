<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealList;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\SplitRecipeScenario;

/**
 * Planifier une recette dont les produits n'ont pas tous la même destination (ADR 0036 décisions 3 et 7 à 12, LST-005), en mémoire : chaque liste
 * visée reçoit son repas et ses lignes, tout est jugé avant la première écriture, et un compte sans type ne voit rien changer.
 */
class PlannedMealSplitHandlerTest extends TestCase
{
    private SplitRecipeScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new SplitRecipeScenario();
    }

    public function testEachListReceivesItsMealAndTheProductsOfItsDestination(): void
    {
        // Given : les trois listes sont en cours
        $s = $this->scenario;
        $lists = $s->openLists();

        // When
        $result = ($s->planned())($s->command());

        // Then : la liste principale d'abord, puis les types dans l'ordre où la recette les atteint (le lardon, puis le beurre)
        $this->assertSame(['main', 'butcher', 'creamery'], array_map(static fn(PlannedMealList $list): string => $list->id, $result->lists));
        $this->assertSame([null, $s->butcher->getId(), $s->creamery->getId()], array_map(static fn(PlannedMealList $list): ?string => $list->typeId, $result->lists));
        // Chaque liste ne porte que les produits de sa destination, dans l'unité d'achat
        $this->assertEquals([$s->egg->getSlug() => 2.0, $s->flour->getSlug() => 0.5], $s->rows($s->reload('main')));
        $this->assertEquals([$s->bacon->getSlug() => 250.0], $s->rows($s->reload('butcher')));
        $this->assertEquals([$s->butter->getSlug() => 0.5], $s->rows($s->reload('creamery')));
        // Un repas par liste, le même groupe, et chacun se souvient de ses produits
        $meals = array_map(fn(string $id) => $s->meal($s->reload($id)), ['main', 'butcher', 'creamery']);
        $this->assertSame(1, count(array_unique(array_map(static fn($meal): ?string => $meal->getPlannedGroupId(), $meals))));
        $this->assertNotNull($meals[0]->getPlannedGroupId());
        $this->assertEqualsCanonicalizing([$s->flour->getSlug(), $s->egg->getSlug()], $meals[0]->getPlacedIngredients());
        $this->assertSame([$s->bacon->getSlug()], $meals[1]->getPlacedIngredients());
        $this->assertSame([$s->butter->getSlug()], $meals[2]->getPlacedIngredients());
        $this->assertSame([SplitRecipeScenario::SERVING, SplitRecipeScenario::SERVING, SplitRecipeScenario::SERVING], array_map(static fn($meal): int => $meal->getServing(), $meals));
        // Les trois listes existaient déjà : rien n'a été ouvert
        $this->assertSame([], $s->typed->events->eventDispatched);
        $this->assertSame(['main', 'butcher', 'creamery'], array_map(static fn($list): string => $list->getId(), array_values($lists)));
    }

    public function testQuantitiesFollowTheNumberOfPeople(): void
    {
        // Given
        $s = $this->scenario;
        $s->openLists();

        // When : pour 8 personnes, le double de la recette de 4
        ($s->planned())($s->command(8));

        // Then
        $this->assertEquals([$s->egg->getSlug() => 4.0, $s->flour->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertEquals([$s->bacon->getSlug() => 500.0], $s->rows($s->reload('butcher')));
        $this->assertEquals([$s->butter->getSlug() => 1.0], $s->rows($s->reload('creamery')));
        $this->assertSame(8, $s->meal($s->reload('butcher'))->getServing());
    }

    public function testAListOfATypeWithoutOneInProgressIsOpenedOnDemandAndNamedLikeTheType(): void
    {
        // Given : seule la liste principale est en cours
        $s = $this->scenario;
        $s->list('main');

        // When
        $result = ($s->planned())($s->command());

        // Then : une liste Boucherie et une liste Crèmerie, ouvertes à la demande, nommées comme leur type
        $this->assertCount(3, $result->lists);
        [$main, $butcher, $creamery] = $result->lists;
        $this->assertSame(['main', null], [$main->id, $main->typeId]);
        $this->assertSame(['Boucherie', $s->butcher->getId(), ShoppingListStatus::ACTIVE], [$butcher->name, $butcher->typeId, $s->reload($butcher->id)->getStatus()]);
        $this->assertSame(['Crèmerie', $s->creamery->getId()], [$creamery->name, $creamery->typeId]);
        $this->assertEquals([$s->bacon->getSlug() => 250.0], $s->rows($s->reload($butcher->id)));
        $this->assertCount(2, $s->typed->events->eventDispatched);
        $this->assertContainsOnlyInstancesOf(NewShoppingListCreatedEvent::class, $s->typed->events->eventDispatched);
        // La liste principale n'a pas été mise en pause par l'ouverture des autres
        $this->assertSame(ShoppingListStatus::ACTIVE, $s->reload('main')->getStatus());
    }

    public function testAProductChosenApartFollowsItsOwnChoiceAndNotItsDepartment(): void
    {
        // Given : le beurre est à part dans la liste principale, malgré le type de son rayon
        $s = $this->scenario;
        $s->list('main');
        $s->ingredients->save($s->butter->changeListDestination('main'));

        // When
        $result = ($s->planned())($s->command());

        // Then : plus de liste Crèmerie, le beurre est dans la liste principale
        $this->assertCount(2, $result->lists);
        $this->assertEquals([$s->butter->getSlug() => 0.5, $s->egg->getSlug() => 2.0, $s->flour->getSlug() => 0.5], $s->rows($s->reload('main')));
        $this->assertEqualsCanonicalizing([$s->flour->getSlug(), $s->egg->getSlug(), $s->butter->getSlug()], $s->meal($s->reload('main'))->getPlacedIngredients());
    }

    public function testAnAccountWithoutAnyTypeKeepsOneMealWithoutGroupOnTheMainList(): void
    {
        // Given : le même compte avant d'avoir créé un type
        $s = new SplitRecipeScenario(withTypes: false);
        $s->list('main');

        // When
        $result = ($s->planned())($s->command());

        // Then : la liste principale seule, un repas qui pose toute la recette, aucun groupe
        $this->assertSame(['main'], array_map(static fn(PlannedMealList $list): string => $list->id, $result->lists));
        $meal = $s->meal($s->reload('main'));
        $this->assertNull($meal->getPlannedGroupId());
        $this->assertNull($meal->getPlacedIngredients());
        $this->assertCount(4, $s->rows($s->reload('main')));
        $this->assertSame([], $s->typed->events->eventDispatched);
    }

    public function testARecipeWhoseProductsAllGoToATypeNeedsNoMainListAndOpensTheListOfTheType(): void
    {
        // Given : tous les rayons de la recette sont dans la Boucherie ; aucune liste principale n'est en cours
        $s = $this->scenario;
        $s->typed->type($s->butcher->getId(), $s->owner->getId(), 'Boucherie', ['viande', 'fromage', 'divers', 'boulangerie']);

        // When
        $result = ($s->planned())($s->command());

        // Then : une seule liste, ouverte, sans groupe ni répartition (le repas pose toute la recette)
        $this->assertCount(1, $result->lists);
        $this->assertSame($s->butcher->getId(), $result->lists[0]->typeId);
        $list = $s->reload($result->lists[0]->id);
        $this->assertCount(4, $list->getRows());
        $this->assertNull($s->meal($list)->getPlannedGroupId());
        $this->assertNull($s->meal($list)->getPlacedIngredients());
    }

    public function testTheMainListIsNeverOpenedSilentlyWhenAProductGoesThere(): void
    {
        // Given : la Boucherie est en cours, la liste principale n'existe pas
        $s = $this->scenario;
        $s->list('butcher', $s->butcher->getId());

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        try {
            ($s->planned())($s->command());
        } finally {
            // rien n'est écrit : ni repas dans la Boucherie, ni liste ouverte
            $this->assertCount(0, $s->reload('butcher')->getMeals());
            $this->assertSame(1, $s->lists->findByOwner($s->owner->getId())->count());
        }
    }

    public function testARecipeAlreadyInAVisitedListRefusesEverythingAndWritesNothing(): void
    {
        // Given : la Boucherie a déjà la recette (planifiée seule, avant les types), la liste principale et la Crèmerie sont libres
        $s = $this->scenario;
        $main = $s->list('main');
        $s->list('butcher', $s->butcher->getId())->addMeal('old-meal', $s->recipe, 2);

        // Then
        $this->expectExceptionObject(ShoppingListException::recipeAlreadyInList('Gâteau salé'));

        // When
        try {
            ($s->planned())($s->command());
        } finally {
            $this->assertCount(0, $main->getMeals());
            $this->assertCount(0, $main->getRows());
            $this->assertSame(2, $s->lists->findByOwner($s->owner->getId())->count(), 'La liste Crèmerie n\'est pas ouverte');
            $this->assertSame([], $s->typed->events->eventDispatched);
        }
    }

    public function testARecipeAlreadyInTheMainListRefusesEverythingAndWritesNothing(): void
    {
        // Given
        $s = $this->scenario;
        $main = $s->list('main');
        $main->addMeal('old-meal', $s->recipe, 2);
        $butcher = $s->list('butcher', $s->butcher->getId());

        // Then
        $this->expectExceptionObject(ShoppingListException::recipeAlreadyInList('Gâteau salé'));

        // When
        try {
            ($s->planned())($s->command());
        } finally {
            $this->assertCount(0, $butcher->getMeals());
            $this->assertCount(0, $butcher->getRows());
        }
    }

    public function testAMissingConversionRefusesEverythingBeforeAnyListIsOpenedOrWritten(): void
    {
        // Given : le beurre ne se convertit plus en plaquette
        $s = $this->scenario;
        $main = $s->list('main');
        $s->conversionRepository->delete($s->conversionRepository->findByIngredientOrCommon($s->butter)->find(fn($c) => $c->getIngredient() !== null));

        // Then
        $this->expectException(IngredientException::class);

        // When
        try {
            ($s->planned())($s->command());
        } finally {
            $this->assertCount(0, $main->getMeals());
            $this->assertCount(0, $main->getRows());
            $this->assertSame(1, $s->lists->findByOwner($s->owner->getId())->count());
            $this->assertSame([], $s->typed->events->eventDispatched);
        }
    }

    public function testASentListOfATypeIsNeverModifiedAndTheTypeOpensAFreshList(): void
    {
        // Given : la Boucherie est envoyée au drive
        $s = $this->scenario;
        $s->list('main');
        $sent = $s->list('sent-butcher', $s->butcher->getId(), ShoppingListStatus::SENT);
        $rowsBefore = $s->rows($sent);

        // When
        $result = ($s->planned())($s->command());

        // Then
        $butcherList = array_values(array_filter($result->lists, fn(PlannedMealList $list): bool => $list->typeId === $s->butcher->getId()))[0];
        $this->assertNotSame('sent-butcher', $butcherList->id);
        $this->assertEquals([$s->bacon->getSlug() => 250.0], $s->rows($s->reload($butcherList->id)));
        $this->assertSame(ShoppingListStatus::SENT, $s->reload('sent-butcher')->getStatus());
        $this->assertSame($rowsBefore, $s->rows($s->reload('sent-butcher')));
        $this->assertCount(0, $s->reload('sent-butcher')->getMeals());
    }

    public function testIfMatchIsJudgedOnTheMainListAndIgnoredForTheListsOfAType(): void
    {
        // Given : la liste principale est à la version 3, la Boucherie à la 7
        $s = $this->scenario;
        $s->list('main', version: 3);
        $s->list('butcher', $s->butcher->getId(), version: 7);

        // When : le client a lu la version 3 de la liste principale
        ($s->planned())($s->command(expectedVersion: 3));

        // Then : la Boucherie, de version 7, n'a rien refusé
        $this->assertCount(1, $s->reload('butcher')->getMeals());
    }

    public function testAnOutdatedMainListRefusesEverythingAndWritesNothingInTheListsOfTheTypes(): void
    {
        // Given : le client a lu la version 7, qui est celle de la Boucherie et non de la liste principale (à la version 3)
        $s = $this->scenario;
        $s->list('main', version: 3);
        $butcher = $s->list('butcher', $s->butcher->getId(), version: 7);

        // Then
        $this->expectExceptionObject(ShoppingListException::outdated('main'));

        // When
        try {
            ($s->planned())($s->command(expectedVersion: 7));
        } finally {
            $this->assertCount(0, $butcher->getMeals());
            $this->assertCount(0, $butcher->getRows());
        }
    }

    public function testTheRecipeOfAnotherUserIsUnknownAndNothingIsOpened(): void
    {
        // Given
        $s = $this->scenario;
        $main = $s->list('main');
        $command = new \Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand('someone-else', $s->recipe->getId(), 4);

        // Then
        $this->expectExceptionObject(RecipeException::notFound($s->recipe->getId()));

        // When
        try {
            ($s->planned())($command);
        } finally {
            $this->assertCount(0, $main->getMeals());
            $this->assertSame(1, $s->lists->findByOwner($s->owner->getId())->count());
        }
    }

    public function testTheDestinationsAreReadOnceWhateverTheNumberOfProducts(): void
    {
        // Given
        $s = $this->scenario;
        $s->openLists();

        // When
        ($s->planned())($s->command());

        // Then : une seule lecture des destinations pour les quatre produits
        $this->assertSame(1, $s->typed->routing->reads);
    }

    public function testTheConversionsOfAllTheProductsAreReadInOneGo(): void
    {
        // Given : farine (gramme -> kilo) et beurre (gramme -> plaquette) se convertissent, lardon et œuf non
        $s = $this->scenario;
        $s->openLists();

        // When
        ($s->planned())($s->command());

        // Then : une lecture pour les ingrédients à convertir, pas une par ingrédient
        $this->assertSame(1, $s->conversionRepository->batchReads);
        $this->assertSame(0, $s->conversionRepository->singleReads);
    }
}

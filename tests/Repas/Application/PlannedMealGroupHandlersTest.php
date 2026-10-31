<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\SplitRecipeScenario;

/**
 * Changer le nombre de personnes d'un repas ou retirer la recette agit sur TOUT le groupe de repas d'une recette éclatée entre plusieurs listes
 * (ADR 0036 décision 9, LST-005), en une transaction ; chaque liste ne touche qu'aux lignes que son repas y avait posées (décision 5). Si une
 * liste du groupe est envoyée au drive, tout est refusé et rien n'est écrit.
 */
class PlannedMealGroupHandlersTest extends TestCase
{
    private SplitRecipeScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new SplitRecipeScenario();
    }

    private function mealId(string $listId): string
    {
        return $this->scenario->meal($this->scenario->reload($listId))->getId();
    }

    public function testChangingTheServingOfOneMealChangesEveryMealOfTheGroup(): void
    {
        // Given : la recette est dans la liste principale, la Boucherie et la Crèmerie, pour 4 personnes
        $s = $this->scenario;
        $s->plannedEverywhere();

        // When : on passe à 8 depuis le repas de la Boucherie
        ($s->serving())(new UpdateServingMealCommand($this->mealId('butcher'), 8));

        // Then : chaque liste a doublé ses propres lignes, chaque repas est à 8
        $this->assertEquals([$s->egg->getSlug() => 4.0, $s->flour->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertEquals([$s->bacon->getSlug() => 500.0], $s->rows($s->reload('butcher')));
        $this->assertEquals([$s->butter->getSlug() => 1.0], $s->rows($s->reload('creamery')));
        foreach (['main', 'butcher', 'creamery'] as $id) {
            $this->assertSame(8, $s->meal($s->reload($id))->getServing(), $id);
        }
    }

    public function testLoweringTheServingDeductsFromEveryListOfTheGroup(): void
    {
        // Given
        $s = $this->scenario;
        $s->plannedEverywhere(8);

        // When : on passe à 2 depuis la liste principale
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 2));

        // Then
        $this->assertEquals([$s->egg->getSlug() => 1.0, $s->flour->getSlug() => 0.25], $s->rows($s->reload('main')));
        $this->assertEquals([$s->bacon->getSlug() => 125.0], $s->rows($s->reload('butcher')));
        $this->assertEquals([$s->butter->getSlug() => 0.25], $s->rows($s->reload('creamery')));
    }

    public function testASentListInTheGroupRefusesEverythingAndWritesNothing(): void
    {
        // Given : la Crèmerie part au drive après la planification
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->reload('creamery')->send(new \DateTimeImmutable());

        // Then
        $this->expectExceptionObject(ShoppingListException::sent('creamery'));

        // When
        try {
            ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 8));
        } finally {
            foreach (['main', 'butcher', 'creamery'] as $id) {
                $this->assertSame(SplitRecipeScenario::SERVING, $s->meal($s->reload($id))->getServing(), $id);
            }
            $this->assertEquals([$s->egg->getSlug() => 2.0, $s->flour->getSlug() => 0.5], $s->rows($s->reload('main')));
            $this->assertEquals([$s->bacon->getSlug() => 250.0], $s->rows($s->reload('butcher')));
        }
    }

    public function testASentListRefusesEvenWhenTheServingDoesNotChange(): void
    {
        // Given
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->reload('butcher')->send(new \DateTimeImmutable());

        // Then
        $this->expectExceptionObject(ShoppingListException::sent('butcher'));

        // When
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), SplitRecipeScenario::SERVING));
    }

    public function testAFinishedListOfTheGroupIsHistoryAndStaysAsItIs(): void
    {
        // Given : la Crèmerie est terminée
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->reload('creamery')->completed();

        // When
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 8));

        // Then
        $this->assertEquals([$s->butter->getSlug() => 0.5], $s->rows($s->reload('creamery')));
        $this->assertSame(SplitRecipeScenario::SERVING, $s->meal($s->reload('creamery'))->getServing());
        $this->assertEquals([$s->bacon->getSlug() => 500.0], $s->rows($s->reload('butcher')));
    }

    public function testAPausedListOfTheGroupFollowsTheGroup(): void
    {
        // Given : la Crèmerie est en pause (c'est ce que devient la liste d'un type supprimé)
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->reload('creamery')->detachFromListType();

        // When
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 8));

        // Then
        $this->assertEquals([$s->butter->getSlug() => 1.0], $s->rows($s->reload('creamery')));
    }

    public function testAMealPlannedAloneActsAloneAsBefore(): void
    {
        // Given : la recette est dans la liste principale seule, sans groupe (un compte sans type)
        $s = new SplitRecipeScenario(withTypes: false);
        $s->list('main');
        ($s->planned())($s->command());
        $other = $s->list('other');
        $other->addMeal('other-meal', $s->recipe, 4);

        // When
        ($s->serving())(new UpdateServingMealCommand($s->meal($s->reload('main'))->getId(), 8));

        // Then : les quatre produits ont doublé dans la liste principale, une autre liste n'a pas bougé
        $this->assertEquals([$s->egg->getSlug() => 4.0, $s->flour->getSlug() => 1.0, $s->bacon->getSlug() => 500.0, $s->butter->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertSame(4, $s->reload('other')->getMeals()->reset()->getServing());
    }

    public function testAProductWhoseDestinationChangedAfterPlanningKeepsItsLineWhereItWas(): void
    {
        // Given : le lardon a été posé dans la Boucherie ; ensuite il est mis à part dans la liste principale
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->ingredients->save($s->bacon->changeListDestination('main'));

        // When : on change les personnes, puis on retire la recette
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 8));

        // Then : la ligne de lardon a doublé dans la Boucherie, la liste principale n'en a pas reçu
        $this->assertEquals([$s->bacon->getSlug() => 500.0], $s->rows($s->reload('butcher')));
        $this->assertArrayNotHasKey($s->bacon->getSlug(), $s->rows($s->reload('main')));

        // And
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));
        $this->assertSame([], $s->rows($s->reload('butcher')));
        $this->assertSame([], $s->rows($s->reload('main')));
    }

    public function testIfMatchIsJudgedOnTheMainListOnlyWhenItIsInTheGroup(): void
    {
        // Given : la liste principale est à la version 1, les autres à une autre
        $s = $this->scenario;
        $s->plannedEverywhere();

        // When : une version périmée de la liste principale refuse tout
        try {
            ($s->serving())(new UpdateServingMealCommand($this->mealId('butcher'), 8, expectedVersion: 99));
            $this->fail('La version de la liste principale est périmée.');
        } catch (ShoppingListException $exception) {
            $this->assertEquals(ShoppingListException::outdated('main'), $exception);
        }
        $this->assertSame(SplitRecipeScenario::SERVING, $s->meal($s->reload('butcher'))->getServing());

        // And : la bonne version passe, quelle que soit celle des listes d'un type
        ($s->serving())(new UpdateServingMealCommand($this->mealId('butcher'), 8, expectedVersion: 1));
        $this->assertSame(8, $s->meal($s->reload('butcher'))->getServing());
    }

    public function testRemovingTheRecipeRemovesEveryMealOfTheGroupAndEveryLineItPlaced(): void
    {
        // Given : les listes portent aussi d'autres produits que la recette y a posés, ajoutés à la main
        $s = $this->scenario;
        $lists = $s->plannedEverywhere();
        $lists['butcher']->addRow('saucisse', 'gramme', 300);
        $lists['main']->addRow($s->egg->getSlug(), 'unite', 1);

        // When
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));

        // Then : plus de repas nulle part, les lignes de la recette ont disparu, le reste demeure
        foreach (['main', 'butcher', 'creamery'] as $id) {
            $this->assertNull($s->meal($s->reload($id)), $id);
        }
        $this->assertEquals([$s->egg->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertEquals(['saucisse' => 300.0], $s->rows($s->reload('butcher')));
        $this->assertSame([], $s->rows($s->reload('creamery')));
    }

    public function testRemovingTheRecipeFindsTheMealInATypedListWhenNoProductWentToTheMainList(): void
    {
        // Given : tous les rayons de la recette sont dans la Boucherie ; la recette n'a laissé aucun repas dans la liste principale
        $s = $this->scenario;
        $s->typed->type($s->butcher->getId(), $s->owner->getId(), 'Boucherie', ['viande', 'fromage', 'divers', 'boulangerie']);
        $s->list('main');
        $s->list('butcher', $s->butcher->getId());
        ($s->planned())($s->command());
        $this->assertNull($s->meal($s->reload('main')));

        // When
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));

        // Then
        $this->assertNull($s->meal($s->reload('butcher')));
        $this->assertSame([], $s->rows($s->reload('butcher')));
    }

    public function testRemovingTheRecipeWithASentListInTheGroupRefusesEverything(): void
    {
        // Given
        $s = $this->scenario;
        $s->plannedEverywhere();
        $s->reload('butcher')->send(new \DateTimeImmutable());

        // Then
        $this->expectExceptionObject(ShoppingListException::sent('butcher'));

        // When
        try {
            ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));
        } finally {
            foreach (['main', 'butcher', 'creamery'] as $id) {
                $this->assertNotNull($s->meal($s->reload($id)), $id);
            }
            $this->assertCount(2, $s->rows($s->reload('main')));
            $this->assertSame(ShoppingListStatus::SENT, $s->reload('butcher')->getStatus());
        }
    }

    public function testRemovingARecipeThatIsNotPlannedDoesNothing(): void
    {
        // Given
        $s = $this->scenario;
        $s->openLists();

        // When / Then
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));
        $this->assertNull($s->meal($s->reload('main')));
    }

    public function testRemovingWithoutAnyListInProgressIsRefused(): void
    {
        // Given
        $s = $this->scenario;

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId()));
    }

    public function testIfMatchOnRemovalIsJudgedOnTheMainListOfTheGroup(): void
    {
        // Given
        $s = $this->scenario;
        $s->plannedEverywhere();

        // Then
        $this->expectExceptionObject(ShoppingListException::outdated('main'));

        // When
        try {
            ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->recipe->getId(), expectedVersion: 99));
        } finally {
            $this->assertNotNull($s->meal($s->reload('butcher')));
        }
    }

    public function testTheMealsOfAGroupOfAnotherPlanningAreNotTouched(): void
    {
        // Given : la recette a été planifiée deux fois, d'abord éclatée (dans les trois listes), puis seule dans une autre liste de la liste principale
        $s = $this->scenario;
        $s->plannedEverywhere();
        $paused = $s->list('paused-main', status: ShoppingListStatus::PAUSED);
        $paused->addMeal('paused-meal', $s->recipe, 4);

        // When
        ($s->serving())(new UpdateServingMealCommand($this->mealId('main'), 8));

        // Then : le repas de la liste en pause n'est pas du groupe
        $this->assertSame(4, $s->reload('paused-main')->getMeals()->reset()->getServing());
    }
}

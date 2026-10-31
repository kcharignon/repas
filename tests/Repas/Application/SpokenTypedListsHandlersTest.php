<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientHandler;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsHandler;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealHandler;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientHandler;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Repas\Application\Port\DefaultServingCommandPort;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\SplitRecipeScenario;

/**
 * La commande vocale et les listes d'un type (ADR 0036 décision 17, LST-008), avec les dépôts en mémoire : ajouter un produit va dans la liste de sa
 * destination, retirer cherche dans toutes les listes en cours, planifier / retirer un repas agit sur toutes les listes visées (le groupe de repas) et
 * dit lesquelles. Le « Gâteau salé » de `SplitRecipeScenario` : lardon -> Boucherie, beurre -> Crèmerie, farine et œufs -> liste principale.
 */
class SpokenTypedListsHandlersTest extends TestCase
{
    private SplitRecipeScenario $s;
    private UnitInMemoryRepository $units;

    protected function setUp(): void
    {
        $this->s = new SplitRecipeScenario();
        $this->units = new UnitInMemoryRepository();
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isBox()] as $unit) {
            $this->units->save($unit->build());
        }
    }

    private function add(): AddSpokenIngredientHandler
    {
        return new AddSpokenIngredientHandler(
            shoppingListRepository: $this->s->lists,
            ingredientRepository: $this->s->ingredients,
            slugResolver: new IngredientSlugResolver(new IngredientAliasInMemoryRepository()),
            createShoppingListHandler: new CreateShoppingListHandler($this->s->lists, $this->s->typed->events, new \Repas\Tests\Helper\FrozenClock(new \DateTimeImmutable()), $this->s->typed->listTypes),
            unitRepository: $this->units,
            conversionService: new ConversionService($this->s->conversionRepository, $this->units),
            listRoutingRepository: $this->s->typed->routing,
            activeListForType: $this->s->typed->activeListForType,
            listName: new SpokenListName($this->s->typed->listTypes),
        );
    }

    private function remove(): RemoveSpokenIngredientHandler
    {
        return new RemoveSpokenIngredientHandler(
            shoppingListRepository: $this->s->lists,
            slugResolver: new IngredientSlugResolver(new IngredientAliasInMemoryRepository()),
            unitRepository: $this->units,
            ingredientRepository: $this->s->ingredients,
            conversionService: new ConversionService($this->s->conversionRepository, $this->units),
            listName: new SpokenListName($this->s->typed->listTypes),
        );
    }

    private function plan(): PlanSpokenMealHandler
    {
        return new PlanSpokenMealHandler(
            $this->s->lists,
            $this->s->recipes,
            $this->s->planned(),
            new class implements DefaultServingCommandPort {
                public function of(string $userId): int
                {
                    return SplitRecipeScenario::SERVING;
                }
            },
            new SpokenListName($this->s->typed->listTypes),
        );
    }

    private function removeMeal(): RemoveSpokenMealHandler
    {
        return new RemoveSpokenMealHandler($this->s->lists, $this->s->recipes, $this->s->removal(), $this->s->serving(), $this->s->typed->group);
    }

    private function changeServings(): ChangeSpokenMealServingsHandler
    {
        return new ChangeSpokenMealServingsHandler($this->s->lists, $this->s->recipes, $this->s->serving(), $this->s->typed->group);
    }

    public function testAProductAddsToTheListOfItsDestinationAndTheAnswerNamesIt(): void
    {
        // Given : la liste principale est en cours, la Boucherie n'a pas de liste
        $this->s->list('main');

        // When : le lardon (rayon viande) va dans la Boucherie
        $result = ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 'unused', 2));

        // Then : une liste Boucherie est ouverte à la demande, la principale n'a rien reçu, rien n'est « créé » ni « envoyé »
        $this->assertSame('Boucherie', $result->listName);
        $this->assertFalse($result->listCreated);
        $this->assertFalse($result->previousListSent);
        $this->assertSame([], $this->s->rows($this->s->reload('main')));
        $butcher = $this->s->lists->findOneActiveByOwnerAndType($this->s->owner->getId(), $this->s->butcher->getId());
        $this->assertEquals([$this->s->bacon->getSlug() => 2.0], $this->s->rows($butcher));
        $this->assertSame(ShoppingListStatus::ACTIVE, $butcher->getStatus());
    }

    public function testAProductWhoseDestinationIsTheMainListKeepsToday(): void
    {
        // Given : aucune liste du tout
        // When : l'œuf n'a pas de type
        $result = ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->egg->getSlug(), 'created-main'));

        // Then : la liste principale est créée comme avant les types, sans nom de liste
        $this->assertNull($result->listName);
        $this->assertTrue($result->listCreated);
        $this->assertEquals([$this->s->egg->getSlug() => 1.0], $this->s->rows($this->s->reload('created-main')));
    }

    public function testAnAddToAnExistingListOfATypeSaysTheLineWasThere(): void
    {
        // Given
        $this->s->openLists();
        ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 'unused', 1));

        // When
        $result = ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 'unused', 3));

        // Then : 4 en tout dans la Boucherie, la ligne existait
        $this->assertSame(['Boucherie', true, 4.0], [$result->listName, $result->lineExisted, $result->quantity]);
        $this->assertEquals([$this->s->bacon->getSlug() => 4.0], $this->s->rows($this->s->reload('butcher')));
    }

    public function testRemovingFindsTheLineInAListOfATypeAndNamesIt(): void
    {
        // Given : du lardon dans la Boucherie seulement
        $this->s->openLists();
        ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 'unused', 3));

        // When
        $result = ($this->remove())(new RemoveSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 1));

        // Then
        $this->assertSame([true, 'Boucherie', 2.0], [$result->found, $result->listName, $result->remaining]);
        $this->assertEquals([$this->s->bacon->getSlug() => 2.0], $this->s->rows($this->s->reload('butcher')));
    }

    public function testRemovingPrefersTheMainListWhenItCarriesTheLine(): void
    {
        // Given : du lardon dans la liste principale (ajouté à la main) ET dans la Boucherie
        $lists = $this->s->openLists();
        $lists['main']->addIngredient($this->s->bacon->getSlug(), 'gramme', 100);
        $lists['main']->addRow($this->s->bacon->getSlug(), 'gramme', 100);
        $this->s->lists->save($lists['main']);
        ($this->add())(new AddSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 'unused', 3));

        // When
        $result = ($this->remove())(new RemoveSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 1));

        // Then : la liste principale d'abord, sans nom de liste ; la Boucherie ne bouge pas
        $this->assertSame([true, null], [$result->found, $result->listName]);
        $this->assertEquals([$this->s->bacon->getSlug() => 99.0], $this->s->rows($this->s->reload('main')));
        $this->assertEquals([$this->s->bacon->getSlug() => 3.0], $this->s->rows($this->s->reload('butcher')));
    }

    public function testRemovingAProductNoListCarriesWritesNothing(): void
    {
        // Given
        $this->s->openLists();

        // When
        $result = ($this->remove())(new RemoveSpokenIngredientCommand($this->s->owner->getId(), $this->s->bacon->getSlug(), 1));

        // Then
        $this->assertFalse($result->found);
    }

    public function testPlanningNamesTheListsTheMealWentTo(): void
    {
        // Given : les trois listes sont en cours
        $this->s->openLists();

        // When
        $result = ($this->plan())(new PlanSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 'unused'));

        // Then : la liste principale et les deux types, dans l'ordre où la recette les atteint
        $this->assertSame(['Boucherie', 'Crèmerie'], $result->typedListNames);
        $this->assertTrue($result->inMainList);
        $this->assertFalse($result->alreadyPlanned);
        $this->assertFalse($result->listCreated);
    }

    public function testPlanningCreatesTheMainListOnlyWhenAProductGoesThere(): void
    {
        // Given : aucune liste, des produits dans la liste principale
        // When
        $result = ($this->plan())(new PlanSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 'created-main'));

        // Then : la liste principale est créée comme avant, les types sont ouverts
        $this->assertTrue($result->listCreated);
        $this->assertTrue($result->inMainList);
        $this->assertSame(['Boucherie', 'Crèmerie'], $result->typedListNames);
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->s->reload('created-main')->getStatus());
    }

    public function testARecipeWhoseProductsAllGoToATypeCreatesNoMainList(): void
    {
        // Given : tous les rayons de la recette sont dans la Boucherie
        $this->s->typed->type($this->s->butcher->getId(), $this->s->owner->getId(), 'Boucherie', ['viande', 'fromage', 'divers', 'boulangerie']);

        // When
        $result = ($this->plan())(new PlanSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 'never-created'));

        // Then : aucune liste principale
        $this->assertSame([false, false, ['Boucherie']], [$result->listCreated, $result->inMainList, $result->typedListNames]);
        $this->assertCount(1, $this->s->lists->findByOwner($this->s->owner->getId()));
    }

    public function testARecipeAlreadyInTheListsIsNeverPlannedTwice(): void
    {
        // Given : la recette est déjà dans les trois listes
        $this->s->plannedEverywhere();

        // When
        $result = ($this->plan())(new PlanSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 'unused', 8));

        // Then : rien n'est écrit, le nombre de personnes est celui du repas existant
        $this->assertSame([true, SplitRecipeScenario::SERVING], [$result->alreadyPlanned, $result->servings]);
    }

    public function testRemovingTheMealActsOnTheWholeGroup(): void
    {
        // Given
        $lists = $this->s->plannedEverywhere();

        // When
        $result = ($this->removeMeal())(new RemoveSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId()));

        // Then : le repas et ses lignes ont quitté les trois listes
        $this->assertTrue($result->found);
        $this->assertTrue($result->removedAll);
        foreach (array_keys($lists) as $id) {
            $this->assertSame([[], null], [$this->s->rows($this->s->reload($id)), $this->s->meal($this->s->reload($id))], $id);
        }
    }

    public function testRemovingAMealFoundOnlyInATypedListWorks(): void
    {
        // Given : la recette n'est planifiée que dans la Boucherie
        $this->s->typed->type($this->s->butcher->getId(), $this->s->owner->getId(), 'Boucherie', ['viande', 'fromage', 'divers', 'boulangerie']);
        ($this->plan())(new PlanSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 'unused'));
        $butcher = $this->s->lists->findOneActiveByOwnerAndType($this->s->owner->getId(), $this->s->butcher->getId());

        // When
        $result = ($this->removeMeal())(new RemoveSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId()));

        // Then
        $this->assertTrue($result->removedAll);
        $this->assertNull($this->s->meal($this->s->reload($butcher->getId())));
    }

    public function testLoweringTheMealActsOnTheWholeGroup(): void
    {
        // Given
        $this->s->plannedEverywhere();

        // When : 2 personnes en moins sur 4
        $result = ($this->removeMeal())(new RemoveSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId(), 2));

        // Then
        $this->assertSame([true, false, 2], [$result->found, $result->removedAll, $result->servings]);
        $this->assertEquals([$this->s->bacon->getSlug() => 125.0], $this->s->rows($this->s->reload('butcher')));
        $this->assertSame(2, $this->s->meal($this->s->reload('creamery'))->getServing());
    }

    public function testASentListInTheGroupRefusesTheRemovalAndWritesNothing(): void
    {
        // Given : la Crèmerie est envoyée au drive après la planification
        $lists = $this->s->plannedEverywhere();
        $lists['creamery']->send(new \DateTimeImmutable());
        $this->s->lists->save($lists['creamery']);

        // When
        $removed = ($this->removeMeal())(new RemoveSpokenMealCommand($this->s->owner->getId(), $this->s->recipe->getId()));
        $changed = ($this->changeServings())(new ChangeSpokenMealServingsCommand($this->s->owner->getId(), $this->s->recipe->getId(), 8));

        // Then : « liste envoyée », rien n'a bougé nulle part
        $this->assertTrue($removed->listSent);
        $this->assertTrue($changed->listSent);
        $this->assertEquals([$this->s->bacon->getSlug() => 250.0], $this->s->rows($this->s->reload('butcher')));
        $this->assertSame(SplitRecipeScenario::SERVING, $this->s->meal($this->s->reload('main'))->getServing());
    }

    public function testChangingTheServingsActsOnTheWholeGroup(): void
    {
        // Given
        $this->s->plannedEverywhere();

        // When
        $result = ($this->changeServings())(new ChangeSpokenMealServingsCommand($this->s->owner->getId(), $this->s->recipe->getId(), 8));

        // Then
        $this->assertTrue($result->found);
        $this->assertEquals([$this->s->bacon->getSlug() => 500.0], $this->s->rows($this->s->reload('butcher')));
        $this->assertSame(8, $this->s->meal($this->s->reload('creamery'))->getServing());
    }
}

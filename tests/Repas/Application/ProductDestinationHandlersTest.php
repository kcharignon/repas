<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\SplitRecipeScenario;

/**
 * Augmenter ou diminuer UN produit vise la liste de sa destination (ADR 0036 décisions 3, 7, 11 et 12, LST-005) : augmenter ouvre à la demande la
 * liste d'un type qui n'en a pas ; diminuer cherche d'abord la liste de la destination, sinon n'importe quelle liste en cours qui en a une ligne,
 * et n'ouvre jamais rien.
 */
class ProductDestinationHandlersTest extends TestCase
{
    private SplitRecipeScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new SplitRecipeScenario();
    }

    private function add(): AddIngredientToShoppingListHandler
    {
        $s = $this->scenario;

        return new AddIngredientToShoppingListHandler($s->lists, new IngredientSlugResolver(new IngredientAliasInMemoryRepository()), $s->typed->routing, $s->typed->activeListForType);
    }

    private function remove(): RemoveIngredientToShoppingListHandler
    {
        $s = $this->scenario;

        return new RemoveIngredientToShoppingListHandler($s->lists, $s->ingredients, new IngredientSlugResolver(new IngredientAliasInMemoryRepository()), $s->typed->routing);
    }

    public function testAProductGoesToTheListOfItsDepartmentsType(): void
    {
        // Given : la Boucherie et la liste principale sont en cours
        $s = $this->scenario;
        $s->openLists();

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));

        // Then : le lardon est dans la Boucherie, rien dans la liste principale
        $this->assertEquals([$s->bacon->getSlug() => 1.0], $s->rows($s->reload('butcher')));
        $this->assertSame([], $s->rows($s->reload('main')));
        $this->assertSame([], $s->rows($s->reload('creamery')));
    }

    public function testAProductOfNoTypeGoesToTheMainListAsBefore(): void
    {
        // Given
        $s = $this->scenario;
        $s->openLists();

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->egg->getSlug()));

        // Then
        $this->assertEquals([$s->egg->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertSame([], $s->rows($s->reload('butcher')));
    }

    public function testATypeWithoutAListInProgressOpensOneNamedLikeTheTypeWithoutTouchingTheOthers(): void
    {
        // Given : seule la liste principale est en cours
        $s = $this->scenario;
        $s->list('main');

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->butter->getSlug()));

        // Then
        $lists = $s->lists->findByOwner($s->owner->getId())->toArray();
        $this->assertCount(2, $lists);
        $opened = array_values(array_filter($lists, static fn($list): bool => $list->getId() !== 'main'))[0];
        $this->assertSame(['Crèmerie', $s->creamery->getId(), ShoppingListStatus::ACTIVE], [$opened->getName(), $opened->getListTypeId(), $opened->getStatus()]);
        $this->assertEquals([$s->butter->getSlug() => 1.0], $s->rows($opened));
        $this->assertSame(ShoppingListStatus::ACTIVE, $s->reload('main')->getStatus());
        $this->assertCount(1, $s->typed->events->eventDispatched);
        $this->assertInstanceOf(NewShoppingListCreatedEvent::class, $s->typed->events->eventDispatched[0]);
    }

    public function testASentListOfTheTypeIsNeverModifiedAndTheProductOpensAFreshList(): void
    {
        // Given
        $s = $this->scenario;
        $s->list('main');
        $sent = $s->list('sent-butcher', $s->butcher->getId(), ShoppingListStatus::SENT);
        $rowsBefore = $s->rows($sent);

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));

        // Then
        $this->assertSame($rowsBefore, $s->rows($s->reload('sent-butcher')));
        $this->assertSame(3, $s->lists->findByOwner($s->owner->getId())->count());
    }

    public function testTheMainListIsNeverOpenedSilentlyForAProductOfTheMainList(): void
    {
        // Given : seule la Boucherie est en cours
        $s = $this->scenario;
        $s->list('butcher', $s->butcher->getId());

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->egg->getSlug()));
    }

    public function testAProductChosenApartGoesWhereItWasChosen(): void
    {
        // Given : le lardon est à part dans la liste principale, malgré la Boucherie
        $s = $this->scenario;
        $s->openLists();
        $s->ingredients->save($s->bacon->changeListDestination('main'));

        // When
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));

        // Then
        $this->assertEquals([$s->bacon->getSlug() => 1.0], $s->rows($s->reload('main')));
        $this->assertSame([], $s->rows($s->reload('butcher')));
    }

    public function testIfMatchConcernsTheMainListOnlyAndIsIgnoredForAListOfAType(): void
    {
        // Given : la Boucherie est à la version 7
        $s = $this->scenario;
        $s->list('main');
        $s->list('butcher', $s->butcher->getId(), version: 7);

        // When : un client qui croit à la version 3 ajoute un produit de la Boucherie
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug(), expectedVersion: 3));

        // Then : il passe, la version est celle de la liste principale
        $this->assertEquals([$s->bacon->getSlug() => 1.0], $s->rows($s->reload('butcher')));

        // And : la liste principale juge la version
        $this->expectExceptionObject(ShoppingListException::outdated('main'));
        ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), $s->egg->getSlug(), expectedVersion: 3));
    }

    public function testTheProductOfAnotherUserIsUnknownAndNoListIsOpened(): void
    {
        // Given : un produit qui n'est pas à l'utilisateur va dans la liste principale, puis est refusé
        $s = $this->scenario;
        $s->list('main');

        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        try {
            ($this->add())(new AddIngredientToShoppingListCommand($s->owner->getId(), 'inconnu'));
        } finally {
            $this->assertSame(1, $s->lists->findByOwner($s->owner->getId())->count());
        }
    }

    public function testDecreasingTakesFromTheListOfTheDestinationFirst(): void
    {
        // Given : le lardon est dans la Boucherie ET dans la liste principale (ajouté à la main avant les types)
        $s = $this->scenario;
        $lists = $s->openLists();
        $lists['main']->addIngredient($s->bacon->getSlug(), 'gramme', 3);
        $lists['main']->addRow($s->bacon->getSlug(), 'gramme', 3);
        $lists['butcher']->addIngredient($s->bacon->getSlug(), 'gramme', 5);
        $lists['butcher']->addRow($s->bacon->getSlug(), 'gramme', 5);

        // When
        ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));

        // Then : la Boucherie, la liste de sa destination, perd 1
        $this->assertEquals([$s->bacon->getSlug() => 4.0], $s->rows($s->reload('butcher')));
        $this->assertEquals([$s->bacon->getSlug() => 3.0], $s->rows($s->reload('main')));
    }

    public function testDecreasingFallsBackToAnyListInProgressThatHasALineOfTheProduct(): void
    {
        // Given : la destination du lardon est devenue la liste principale, mais sa ligne est restée dans la Boucherie (rien ne se déplace)
        $s = $this->scenario;
        $lists = $s->openLists();
        $lists['butcher']->addIngredient($s->bacon->getSlug(), 'gramme', 2);
        $lists['butcher']->addRow($s->bacon->getSlug(), 'gramme', 2);
        $s->ingredients->save($s->bacon->changeListDestination('main'));

        // When
        ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));

        // Then
        $this->assertEquals([$s->bacon->getSlug() => 1.0], $s->rows($s->reload('butcher')));
    }

    public function testDecreasingNeverOpensAListAndRefusesWhenNeitherTheDestinationNorAnyListHasTheProduct(): void
    {
        // Given : seule la liste principale est en cours, la Boucherie n'a pas de liste ni le lardon de ligne
        $s = $this->scenario;
        $s->list('main');

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        try {
            ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));
        } finally {
            // rien n'est ouvert
            $this->assertSame(1, $s->lists->findByOwner($s->owner->getId())->count());
            $this->assertSame([], $s->typed->events->eventDispatched);
        }
    }

    public function testDecreasingAProductOfTheMainListWithoutALineKeepsTheListAsItWas(): void
    {
        // Given : la liste principale est en cours, sans ligne d'œuf
        $s = $this->scenario;
        $s->list('main');

        // When : comme avant les types, rien à retirer n'est pas une erreur
        ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->egg->getSlug()));

        // Then
        $this->assertSame([], $s->rows($s->reload('main')));
    }

    public function testDecreasingWithoutAnyListInProgressIsRefused(): void
    {
        // Given : la Boucherie est envoyée au drive, il n'y a rien d'autre
        $s = $this->scenario;
        $s->list('sent-butcher', $s->butcher->getId(), ShoppingListStatus::SENT);

        // Then
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // When
        ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug()));
    }

    public function testDecreasingJudgesIfMatchOnTheMainListOnly(): void
    {
        // Given : le lardon n'est que dans la Boucherie (version 7)
        $s = $this->scenario;
        $s->list('main');
        $butcher = $s->list('butcher', $s->butcher->getId(), version: 7);
        $butcher->addIngredient($s->bacon->getSlug(), 'gramme', 2);
        $butcher->addRow($s->bacon->getSlug(), 'gramme', 2);

        // When
        ($this->remove())(new RemoveIngredientToShoppingListCommand($s->owner->getId(), $s->bacon->getSlug(), expectedVersion: 3));

        // Then
        $this->assertEquals([$s->bacon->getSlug() => 1.0], $s->rows($s->reload('butcher')));
    }
}

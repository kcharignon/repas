<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\PartsScenario;

/**
 * Les sous-ingrédients dans la liste de courses (ADR 0038 décisions 6 à 14, SUB-003) à travers les handlers, en mémoire : planifier, changer les
 * personnes, retirer une recette, ajouter et retirer à la main quand « Jaune d'œuf » et « Blanc d'œuf » sont des parties d'« Œuf ». Une partie n'est
 * jamais une ligne : sa quantité va au registre de la liste et la ligne du parent porte le total.
 */
class PartsHandlersTest extends TestCase
{
    private PartsScenario $s;

    protected function setUp(): void
    {
        $this->s = new PartsScenario();
    }

    public function testThreeEggsTwoYolksAndTwoWhitesMakeFiveEggs(): void
    {
        // Given : trois recettes pour quatre personnes dans la liste en cours
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);
        $s->recipe('meringue', [$s->white->getSlug() => 2]);

        // When
        $s->plan('omelette');
        $s->plan('mousse');
        $s->plan('meringue');

        // Then : « Œuf 5 », jamais « Jaune d'œuf » ni « Blanc d'œuf » en ligne ; le registre garde les parties
        $this->assertEquals(['oeuf' => 5.0], $s->rows());
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0), 'blanc' => new PartShare('oeuf', 2.0, 2.0)], $s->reload()->getPartShares());
        $this->assertCount(3, $s->reload()->getMeals());
    }

    public function testTwoWhitesThenThreeYolksMakeThreeAndNeverFive(): void
    {
        // Given
        $s = $this->s;
        $s->list();
        $s->recipe('meringue', [$s->white->getSlug() => 2]);
        $s->recipe('creme', [$s->yolk->getSlug() => 3]);

        // When
        $s->plan('meringue');
        $this->assertEquals(['oeuf' => 2.0], $s->rows());
        $s->plan('creme');

        // Then
        $this->assertEquals(['oeuf' => 3.0], $s->rows());
    }

    public function testRemovingARecipeTakesBackExactlyItsShare(): void
    {
        // Given : 3 œufs + 3 jaunes + 2 blancs
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->recipe('creme', [$s->yolk->getSlug() => 3]);
        $s->recipe('meringue', [$s->white->getSlug() => 2]);
        $s->plan('omelette');
        $s->plan('creme');
        $s->plan('meringue');
        $this->assertEquals(['oeuf' => 6.0], $s->rows());

        // When : on retire d'abord la recette des 3 œufs
        $s->unplan('omelette');

        // Then : il reste ce que les parties valent
        $this->assertEquals(['oeuf' => 3.0], $s->rows());

        // When : puis celle des 3 jaunes
        $s->unplan('creme');

        // Then : il reste les 2 blancs
        $this->assertEquals(['oeuf' => 2.0], $s->rows());
        $this->assertSame(['blanc'], array_keys($s->reload()->getPartShares()));

        // When : puis la dernière
        $s->unplan('meringue');

        // Then : la ligne a disparu avec le registre
        $this->assertSame([], $s->rows());
        $this->assertSame([], $s->reload()->getPartShares());
        $this->assertCount(0, $s->reload()->getMeals());
    }

    public function testChangingThePeopleScalesThePartsAndTheirEquivalents(): void
    {
        // Given : 4 personnes, 2 jaunes + 3 œufs
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);
        $s->plan('omelette');
        $s->plan('mousse');
        $this->assertEquals(['oeuf' => 5.0], $s->rows());

        // When : la mousse passe à 8 personnes (4 jaunes), puis à 2 (1 jaune)
        $s->changeServing('mousse', 8);
        $this->assertEquals(['oeuf' => 7.0], $s->rows());
        $this->assertEquals(new PartShare('oeuf', 4.0, 4.0), $s->reload()->partShareOf('jaune'));
        $s->changeServing('mousse', 2);

        // Then
        $this->assertEquals(['oeuf' => 4.0], $s->rows());
        $this->assertEquals(new PartShare('oeuf', 1.0, 1.0), $s->reload()->partShareOf('jaune'));
    }

    public function testARatioOfTwoMakesHalfPodsWorthHalfAPod(): void
    {
        // Given : 3 demi-gousses pour quatre personnes
        $s = $this->s;
        $s->list();
        $s->recipe('flan', [$s->half->getSlug() => 3]);

        // When
        $s->plan('flan');

        // Then : une gousse et demie ; le double de personnes en fait trois
        $this->assertEquals(['vanille' => 1.5], $s->rows());
        $s->changeServing('flan', 8);
        $this->assertEquals(['vanille' => 3.0], $s->rows());
        $this->assertEquals(new PartShare('vanille', 6.0, 3.0), $s->reload()->partShareOf('demi-gousse'));
    }

    public function testTwoParentsHaveTheirOwnLargestEquivalent(): void
    {
        // Given
        $s = $this->s;
        $s->list();
        $s->recipe('dessert', [$s->yolk->getSlug() => 2, $s->half->getSlug() => 2, $s->seeds->getSlug() => 8, $s->flour->getSlug() => 500]);

        // When
        $s->plan('dessert');

        // Then : 2 jaunes = 2 œufs ; 2 demi-gousses = 1 gousse et 8 graines = 2 gousses, donc 2 ; la farine est une ligne ordinaire (0,5 kg)
        $this->assertEquals(['farine' => 0.5, 'oeuf' => 2.0, 'vanille' => 2.0], $s->rows());
        $this->assertSame(['jaune', 'demi-gousse', 'graines'], array_keys($s->reload()->getPartShares()));
    }

    public function testAnOrdinaryIngredientMakesTheSameWritesAsBeforeAndReadsNoParent(): void
    {
        // Given : une recette sans partie
        $s = $this->s;
        $s->list();
        $s->recipe('gateau', [$s->egg->getSlug() => 2, $s->flour->getSlug() => 500]);

        // When
        $s->plan('gateau');
        $s->changeServing('gateau', 8);
        $s->unplan('gateau');

        // Then : aucun registre, aucune lecture de parent, aucune lecture de conversion de plus
        $this->assertSame([], $s->reload()->getPartShares());
        $this->assertSame(0, $s->ingredients->batchReads);
        $this->assertSame([], $s->rows());
    }

    public function testTheParentsAreReadOnceWhateverTheNumberOfParts(): void
    {
        // Given : une recette avec une seule partie, puis une avec huit lignes de parties de deux parents
        $s = $this->s;
        $s->list();
        $s->recipe('petite', [$s->yolk->getSlug() => 1]);
        $s->recipe('grande', [$s->yolk->getSlug() => 1, $s->white->getSlug() => 2, $s->half->getSlug() => 3, $s->seeds->getSlug() => 4, $s->egg->getSlug() => 1]);

        // When
        $s->plan('petite');
        $afterSmall = $s->ingredients->batchReads;
        $s->plan('grande');

        // Then : une lecture groupée par planification, quel que soit le nombre de parties ou de parents
        $this->assertSame(1, $afterSmall);
        $this->assertSame(2, $s->ingredients->batchReads);

        // And : changer les personnes en augmentant lit les parents une fois ; en diminuant, jamais
        $s->changeServing('grande', 8);
        $this->assertSame(3, $s->ingredients->batchReads);
        $s->changeServing('grande', 2);
        $s->unplan('grande');
        $this->assertSame(3, $s->ingredients->batchReads);
    }

    public function testAPartPostedByTheHandIsAddedToTheRegistryAndTheParentLineGrows(): void
    {
        // Given : 3 œufs en liste
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->plan('omelette');

        // When : « ajoute un jaune d'œuf » deux fois, puis un blanc, depuis le sélecteur
        $s->add($s->yolk->getSlug());
        $s->add($s->yolk->getSlug());
        $s->add($s->white->getSlug());

        // Then : 2 jaunes, 1 blanc : le plus grand est 2, donc 3 + 2 = 5 ; aucune ligne de partie, aucune entrée de produit ajouté à la main pour elle
        $this->assertEquals(['oeuf' => 5.0], $s->rows());
        $this->assertEquals(new PartShare('oeuf', 2.0, 2.0), $s->reload()->partShareOf('jaune'));
        $this->assertEquals(new PartShare('oeuf', 1.0, 1.0), $s->reload()->partShareOf('blanc'));
        $this->assertCount(0, $s->reload()->getIngredients());
    }

    public function testAPartRemovedByTheHandLeavesTheRegistryProportionally(): void
    {
        // Given : 3 œufs + 2 jaunes ajoutés à la main
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->plan('omelette');
        $s->add($s->yolk->getSlug());
        $s->add($s->yolk->getSlug());
        $this->assertEquals(['oeuf' => 5.0], $s->rows());

        // When : on en retire un, puis le dernier
        $s->remove($s->yolk->getSlug());
        $this->assertEquals(['oeuf' => 4.0], $s->rows());
        $s->remove($s->yolk->getSlug());

        // Then : les 3 œufs de la recette restent, le registre est vide
        $this->assertEquals(['oeuf' => 3.0], $s->rows());
        $this->assertSame([], $s->reload()->getPartShares());
    }

    public function testRemovingAPartThatTheListNeverHadChangesNothing(): void
    {
        // Given : 3 œufs, aucun jaune
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->plan('omelette');

        // When
        $s->remove($s->yolk->getSlug());

        // Then : la ligne du parent n'a pas bougé (jamais une ligne « jaune » à retirer)
        $this->assertEquals(['oeuf' => 3.0], $s->rows());
    }

    public function testAnIngredientSplitAfterThePlanningStillLeavesTheListWhenTheRecipeIsRemoved(): void
    {
        // Given : le jaune était un ingrédient ordinaire quand la recette a été planifiée (une vraie ligne « jaune » dans la liste)
        $s = $this->s;
        $s->ingredients->findOneBySlug($s->yolk->getSlug())->detachFromParent();
        $s->list();
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);
        $s->plan('mousse');
        $this->assertEquals(['jaune' => 2.0], $s->rows());

        // When : il devient une partie d'œuf entre-temps, puis la recette est retirée
        $s->ingredients->findOneBySlug($s->yolk->getSlug())->makePartOf($s->egg->getSlug(), 1.0);
        $s->unplan('mousse');

        // Then : le retrait se décide d'après la liste : pas d'entrée au registre, c'est une ligne ordinaire qui part
        $this->assertSame([], $s->rows());
        $this->assertSame([], $s->reload()->getPartShares());
    }

    public function testAnIngredientGluedBackAfterThePlanningStillLeavesTheListWhenTheRecipeIsRemoved(): void
    {
        // Given : le jaune était une partie quand la recette a été planifiée (3 œufs + 2 jaunes)
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);
        $s->plan('omelette');
        $s->plan('mousse');
        $this->assertEquals(['oeuf' => 5.0], $s->rows());

        // When : le jaune est recollé (il redevient un ingrédient ordinaire) puis la recette est retirée
        $s->ingredients->findOneBySlug($s->yolk->getSlug())->detachFromParent();
        $s->unplan('mousse');

        // Then : la liste a gardé la partie, c'est elle qui part
        $this->assertEquals(['oeuf' => 3.0], $s->rows());
        $this->assertSame([], $s->reload()->getPartShares());
    }

    public function testThePartFollowsTheListOfItsParentAndTheMealRemembersThePart(): void
    {
        // Given : « Marché » tient le rayon divers (l'œuf et ses parties) ; la farine, du rayon boulangerie, reste dans la liste principale ; le
        // jaune a un autre choix de liste, que la règle ignore pour une partie
        $s = $this->s;
        $market = $s->typed->type('market-type', $s->owner->getId(), 'Marché', ['divers']);
        $s->ingredients->findOneBySlug($s->yolk->getSlug())->changeListDestination('main');
        $s->list('main');
        $s->recipe('gateau', [$s->egg->getSlug() => 1, $s->yolk->getSlug() => 2, $s->flour->getSlug() => 500]);

        // When
        $s->plan('gateau');

        // Then : le Marché a été ouvert avec « Œuf 3 » (1 + 2 jaunes), la liste principale n'a que la farine
        $marketList = $s->lists->findOneActiveByOwnerAndType($s->owner->getId(), $market->getId());
        $this->assertNotNull($marketList);
        $this->assertEquals(['oeuf' => 3.0], $s->rows($marketList->getId()));
        $this->assertEquals(['farine' => 0.5], $s->rows('main'));
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0)], $marketList->getPartShares());
        $this->assertSame([], $s->reload('main')->getPartShares());
        // And : chaque repas se souvient des produits de la recette qu'il a posés, la partie sous son propre slug
        $this->assertEqualsCanonicalizing(['oeuf', 'jaune'], $marketList->mealOf($s->recipes->findOneById('gateau'))->getPlacedIngredients());
        $this->assertSame(['farine'], $s->reload('main')->mealOf($s->recipes->findOneById('gateau'))->getPlacedIngredients());

        // When : on passe à 8 personnes, puis on retire la recette : tout le groupe agit
        $s->changeServing('gateau', 8);
        $this->assertEquals(['oeuf' => 6.0], $s->rows($marketList->getId()));
        $this->assertEquals(['farine' => 1.0], $s->rows('main'));
        $s->unplan('gateau');

        // Then
        $this->assertSame([], $s->rows($marketList->getId()));
        $this->assertSame([], $s->reload($marketList->getId())->getPartShares());
        $this->assertSame([], $s->rows('main'));
    }

    public function testAPartAddedByHandGoesToTheListOfItsParent(): void
    {
        // Given : l'œuf et ses parties sont dans le type « Marché », qui n'a pas de liste en cours
        $s = $this->s;
        $market = $s->typed->type('market-type', $s->owner->getId(), 'Marché', ['divers']);
        $s->ingredients->findOneBySlug($s->yolk->getSlug())->changeListDestination('main');
        $s->list('main');

        // When : « ajoute un jaune d'œuf »
        $s->add($s->yolk->getSlug());

        // Then : la liste du Marché est ouverte et porte « Œuf 1 » ; rien dans la liste principale
        $marketList = $s->lists->findOneActiveByOwnerAndType($s->owner->getId(), $market->getId());
        $this->assertNotNull($marketList);
        $this->assertEquals(['oeuf' => 1.0], $s->rows($marketList->getId()));
        $this->assertSame([], $s->rows('main'));

        // When : on le retire
        $s->remove($s->yolk->getSlug());

        // Then : la ligne a disparu avec lui
        $this->assertSame([], $s->rows($marketList->getId()));
    }

    public function testASentListRefusesEveryGestureAndWritesNothing(): void
    {
        // Given : 3 œufs + 2 jaunes, la liste part au drive
        $s = $this->s;
        $s->list();
        $s->recipe('omelette', [$s->egg->getSlug() => 3]);
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);
        $s->recipe('meringue', [$s->white->getSlug() => 2]);
        $s->plan('omelette');
        $s->plan('mousse');
        $s->reload()->send(new \DateTimeImmutable('2026-11-08 10:00:00'));
        $before = [$s->rows(), $s->reload()->getPartShares()];

        // When / Then : planifier, changer les personnes, retirer, ajouter, retirer à la main : tout est refusé
        $attempts = [
            fn() => $s->plan('meringue'),
            fn() => $s->changeServing('mousse', 8),
            fn() => $s->unplan('mousse'),
            fn() => $s->add($s->white->getSlug()),
            fn() => $s->remove($s->yolk->getSlug()),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail("Le geste $index aurait dû être refusé : la liste est envoyée au drive.");
            } catch (ShoppingListException $exception) {
                $this->assertContains($exception->getMessage(), [ShoppingListException::SENT, 'No active shopping list found.'], "Geste $index");
            }
            $this->assertEquals($before, [$s->rows(), $s->reload()->getPartShares()], "Geste $index");
        }
        $this->assertTrue($s->reload()->isSent());
    }

    public function testAMissingParentRefusesTheWholePlanning(): void
    {
        // Given : la partie désigne un parent qui n'existe plus (jamais par l'API ; une donnée abîmée)
        $s = $this->s;
        $s->list();
        $orphan = $s->ingredients->findOneBySlug($s->yolk->getSlug());
        $orphan->makePartOf('disparu', 1.0);
        $s->recipe('mousse', [$s->yolk->getSlug() => 2]);

        // When / Then : jamais une liste fausse
        $this->expectException(IngredientException::class);
        $s->plan('mousse');
    }

    public function testNothingIsWrittenWhenTheParentCannotConvertItsRecipeUnit(): void
    {
        // Given : un parent cuisiné au gramme et acheté à l'unité, sans conversion entre les deux, et sa partie « Pistil »
        $s = $this->s;
        $list = $s->list();
        $parent = new IngredientBuilder()->withName('Safran')->withSlug('safran')->withCreator($s->owner)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->build();
        $part = new IngredientBuilder()->withName('Pistil')->withSlug('pistil')->withCreator($s->owner)
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withParent('safran', 1.0)->build();
        $s->ingredients->save($parent);
        $s->ingredients->save($part);
        $s->recipe('paella', ['pistil' => 3, $s->egg->getSlug() => 2]);
        $versionBefore = $list->getVersion();

        // When / Then : la planification est refusée en entier (une conversion manquante, comme pour tout ingrédient), rien n'est écrit
        try {
            $s->plan('paella');
            $this->fail('Une conversion manquante doit refuser toute la planification.');
        } catch (IngredientException $exception) {
            $this->assertSame(422, $exception->getCode());
        }
        $this->assertCount(0, $s->reload()->getMeals());
        $this->assertSame([], $s->rows());
        $this->assertSame([], $s->reload()->getPartShares());
        $this->assertSame($versionBefore, $s->reload()->getVersion());
    }
}

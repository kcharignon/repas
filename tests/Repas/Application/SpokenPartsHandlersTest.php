<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientHandler;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Domain\Model\AddedItem;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Domain\Model\RemovedItem;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\PartsScenario;

/**
 * Dire « ajoute un jaune d'œuf » et « enlève un jaune d'œuf » (ADR 0038 décisions 11 et 33, SUB-005), en mémoire : une partie n'est jamais une ligne, la
 * quantité dite va à la partie (le registre de la liste de son parent) et le résultat décrit la ligne du PARENT (« Ça fait 3 œufs dans ta liste. »).
 * Un ingrédient ordinaire ne change pas (AddSpokenIngredientHandlerTest, RemoveSpokenIngredientHandlerTest).
 */
class SpokenPartsHandlersTest extends TestCase
{
    private PartsScenario $s;
    private UnitInMemoryRepository $units;

    protected function setUp(): void
    {
        $this->s = new PartsScenario();
        $this->units = new UnitInMemoryRepository();
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo()] as $unit) {
            $this->units->save($unit->build());
        }
    }

    private function add(string $slug, int $quantity = 1, ?string $unit = null): AddedItem
    {
        $handler = new AddSpokenIngredientHandler(
            shoppingListRepository: $this->s->lists,
            ingredientRepository: $this->s->ingredients,
            slugResolver: new IngredientSlugResolver(new IngredientAliasInMemoryRepository()),
            createShoppingListHandler: new CreateShoppingListHandler($this->s->lists, $this->s->typed->events, new FrozenClock(new DateTimeImmutable()), $this->s->typed->listTypes),
            unitRepository: $this->units,
            conversionService: new ConversionService($this->s->conversionRepository, $this->units),
            listRoutingRepository: $this->s->typed->routing,
            activeListForType: $this->s->typed->activeListForType,
            listName: new SpokenListName($this->s->typed->listTypes),
            partLines: $this->s->partLines(),
        );

        return $handler(new AddSpokenIngredientCommand($this->s->owner->getId(), $slug, 'new-list', $quantity, $unit));
    }

    private function remove(string $slug, int $quantity = 1, ?string $unit = null): RemovedItem
    {
        $handler = new RemoveSpokenIngredientHandler(
            shoppingListRepository: $this->s->lists,
            slugResolver: new IngredientSlugResolver(new IngredientAliasInMemoryRepository()),
            unitRepository: $this->units,
            ingredientRepository: $this->s->ingredients,
            conversionService: new ConversionService($this->s->conversionRepository, $this->units),
            listName: new SpokenListName($this->s->typed->listTypes),
        );

        return $handler(new RemoveSpokenIngredientCommand($this->s->owner->getId(), $slug, $quantity, $unit));
    }

    /** Une liste du propriétaire qui contient déjà `$eggs` œufs entiers, et les parties dites `slug => quantité` (leur équivalent est leur quantité : ratio 1). */
    private function listWith(float $eggs, array $parts = [], string $id = 'main', ?string $typeId = null): ShoppingList
    {
        $list = $this->s->list($id, $typeId);
        if ($eggs > 0) {
            $list->addRow($this->s->egg->getSlug(), 'unite', $eggs);
        }
        foreach ($parts as $slug => $quantity) {
            $list->addPart($slug, $this->s->egg->getSlug(), 'unite', $quantity, $quantity);
        }
        $this->s->lists->save($list);

        return $list;
    }

    // --- Ajouter

    public function testAYolkInAListWithoutEggsMakesOneEgg(): void
    {
        // Given : une liste vide
        $this->s->list();

        // When
        $result = $this->add($this->s->yolk->getSlug());

        // Then : la partie n'est jamais une ligne, la ligne du parent dit 1 œuf
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertSame(1.0, $result->quantity);
        $this->assertSame('unite', $result->unitSlug);
        $this->assertSame(1.0, $result->addedQuantity);
        $this->assertFalse($result->lineExisted);
        $this->assertFalse($result->listCreated);
        $this->assertNull($result->listName);
        $this->assertEquals(['oeuf' => 1.0], $this->s->rows());
        $this->assertEquals(['jaune' => new PartShare('oeuf', 1.0, 1.0)], $this->s->reload()->getPartShares());
    }

    public function testAYolkOnTopOfThreeEggsMakesFourAndTheWhiteCompletesIt(): void
    {
        // Given : 3 œufs entiers dans la liste
        $this->listWith(3);

        // When : un jaune, puis un blanc (le même œuf), puis trois jaunes de plus
        $yolk = $this->add($this->s->yolk->getSlug());
        $white = $this->add($this->s->white->getSlug());
        $this->assertSame(4.0, $yolk->quantity);
        $this->assertTrue($yolk->lineExisted);
        $this->assertSame(4.0, $white->quantity);
        $this->assertEquals(['oeuf' => 4.0], $this->s->rows());
        $threeYolks = $this->add($this->s->yolk->getSlug(), 3);

        // Then : 3 œufs + 4 jaunes = 7 (le blanc complète un des jaunes, il ne s'additionne pas)
        $this->assertSame(7.0, $threeYolks->quantity);
        $this->assertSame(3.0, $threeYolks->addedQuantity);
        $this->assertSame('Œuf', $threeYolks->foldedInto);
        $this->assertEquals(['oeuf' => 7.0], $this->s->rows());
        $this->assertEquals(['jaune' => new PartShare('oeuf', 4.0, 4.0), 'blanc' => new PartShare('oeuf', 1.0, 1.0)], $this->s->reload()->getPartShares());
    }

    public function testAYolkWithoutAnyListStartsOne(): void
    {
        // Given : aucune liste

        // When
        $result = $this->add($this->s->yolk->getSlug(), 2);

        // Then : la liste est créée, avec 2 œufs
        $this->assertTrue($result->listCreated);
        $this->assertFalse($result->previousListSent);
        $this->assertSame(2.0, $result->quantity);
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertEquals(['oeuf' => 2.0], $this->s->rows('new-list'));
    }

    public function testAYolkNeverTouchesAListSentToTheDrive(): void
    {
        // Given : la seule liste est partie au drive
        $sent = $this->s->list('sent', null, ShoppingListStatus::SENT);
        $rowsBefore = $this->s->rows('sent');

        // When
        $result = $this->add($this->s->yolk->getSlug());

        // Then : une liste neuve reçoit la partie, la liste envoyée n'a pas bougé
        $this->assertTrue($result->listCreated);
        $this->assertTrue($result->previousListSent);
        $this->assertEquals(['oeuf' => 1.0], $this->s->rows('new-list'));
        $this->assertEquals($sent->getPartShares(), $this->s->reload('sent')->getPartShares());
        $this->assertSame($rowsBefore, $this->s->rows('sent'));
    }

    public function testAYolkGoesToTheListOfItsParentsType(): void
    {
        // Given : un type qui tient le rayon de l'œuf, la liste principale en cours
        $market = $this->s->typed->type('market', $this->s->owner->getId(), 'Marché', [$this->s->egg->getDepartment()->getSlug()]);
        $this->s->list();

        // When : « ajoute un jaune d'œuf »
        $result = $this->add($this->s->yolk->getSlug());

        // Then : la liste du type, ouverte à la demande, reçoit la partie ; la principale n'a rien
        $this->assertSame('Marché', $result->listName);
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertSame(1.0, $result->quantity);
        $this->assertSame([], $this->s->rows());
        $typed = $this->s->lists->findOneActiveByOwnerAndType($this->s->owner->getId(), $market->getId());
        $this->assertSame(1.0, $typed->findRow('oeuf')?->getQuantity());
        $this->assertArrayHasKey('jaune', $typed->getPartShares());
    }

    public function testASaidUnitTheYolkDoesNotKnowIsLeftAside(): void
    {
        // Given
        $this->s->list();

        // When : « deux kilos de jaunes d'œuf »
        $result = $this->add($this->s->yolk->getSlug(), 2, 'kilo');

        // Then : l'unité du jaune est « unité » : la quantité est prise telle quelle et Alexa le sait
        $this->assertTrue($result->unitSubstituted);
        $this->assertSame(2.0, $result->quantity);
    }

    public function testAnOrdinaryIngredientStillMakesItsOwnRow(): void
    {
        // Given / When : la farine, sans partie
        $this->s->list();
        $result = $this->add($this->s->flour->getSlug());

        // Then : exactement ce que faisait l'ajout d'avant les parties
        $this->assertNull($result->foldedInto);
        $this->assertArrayHasKey('farine', $this->s->rows());
        $this->assertSame([], $this->s->reload()->getPartShares());
    }

    // --- Retirer

    public function testRemovingAYolkLowersTheEggsByThatYolk(): void
    {
        // Given : 3 œufs et 2 jaunes = 5 œufs
        $this->listWith(3, ['jaune' => 2.0]);
        $this->assertEquals(['oeuf' => 5.0], $this->s->rows());

        // When : « enlève un jaune d'œuf »
        $result = $this->remove($this->s->yolk->getSlug());

        // Then : « C'est retiré. Il te reste 4 œufs. »
        $this->assertTrue($result->found);
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertSame(4.0, $result->remaining);
        $this->assertSame(1.0, $result->removed);
        $this->assertSame('unite', $result->unitSlug);
        $this->assertEquals(['oeuf' => 4.0], $this->s->rows());
        $this->assertEquals(['jaune' => new PartShare('oeuf', 1.0, 1.0)], $this->s->reload()->getPartShares());
    }

    public function testRemovingAYolkThatIsNotInTheListSaysSo(): void
    {
        // Given : des œufs entiers seulement, pas de jaune
        $this->listWith(3);

        // When
        $result = $this->remove($this->s->yolk->getSlug());

        // Then : rien n'est retiré, la ligne des œufs est intacte
        $this->assertFalse($result->found);
        $this->assertEquals(['oeuf' => 3.0], $this->s->rows());
    }

    public function testRemovingMoreYolksThanTheListHasKeepsTheWholeEggs(): void
    {
        // Given : 3 œufs entiers et 2 jaunes (5 œufs)
        $this->listWith(3, ['jaune' => 2.0]);

        // When : « enlève dix jaunes d'œuf »
        $result = $this->remove($this->s->yolk->getSlug(), 10);

        // Then : l'entrée disparaît, les 3 œufs entiers restent, et on a retiré les 2 jaunes qu'il y avait
        $this->assertTrue($result->found);
        $this->assertSame(2.0, $result->removed);
        $this->assertSame(3.0, $result->remaining);
        $this->assertEquals(['oeuf' => 3.0], $this->s->rows());
        $this->assertSame([], $this->s->reload()->getPartShares());
    }

    public function testRemovingTheLastYolkOfAListWithOnlyYolksEmptiesTheLine(): void
    {
        // Given : 2 jaunes seuls (la ligne des œufs ne vaut que par eux)
        $this->listWith(0, ['jaune' => 2.0]);

        // When
        $result = $this->remove($this->s->yolk->getSlug(), 2);

        // Then : la ligne du parent a disparu avec eux
        $this->assertTrue($result->found);
        $this->assertSame(0.0, $result->remaining);
        $this->assertTrue($result->lineDeleted());
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertSame([], $this->s->rows());
    }

    public function testRemovingTheWholeParentErasesItsEntries(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs = 5 œufs
        $this->listWith(3, ['jaune' => 2.0, 'blanc' => 2.0]);

        // When : « enlève cinq œufs »
        $result = $this->remove($this->s->egg->getSlug(), 5);

        // Then : l'œuf est un ingrédient ordinaire (pas de foldedInto), la ligne et ses parties ont disparu
        $this->assertTrue($result->lineDeleted());
        $this->assertNull($result->foldedInto);
        $this->assertSame([], $this->s->rows());
        $this->assertSame([], $this->s->reload()->getPartShares());
    }

    public function testRemovingSomeEggsKeepsTheYolksThatStillFit(): void
    {
        // Given : 3 œufs + 2 jaunes = 5
        $this->listWith(3, ['jaune' => 2.0]);

        // When : on enlève 2 œufs entiers
        $result = $this->remove($this->s->egg->getSlug(), 2);

        // Then : 3 œufs, qui valent encore les 2 jaunes
        $this->assertSame(3.0, $result->remaining);
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0)], $this->s->reload()->getPartShares());
    }

    public function testRemovingAYolkFromAListOfATypeNamesTheList(): void
    {
        // Given : le type tient le rayon de l'œuf, sa liste porte 3 œufs et 2 jaunes ; la principale n'a rien
        $market = $this->s->typed->type('market', $this->s->owner->getId(), 'Marché', [$this->s->egg->getDepartment()->getSlug()]);
        $this->s->list();
        $this->listWith(3, ['jaune' => 2.0], 'market-list', $market->getId());

        // When
        $result = $this->remove($this->s->yolk->getSlug());

        // Then : la liste du type est celle qui perd le jaune, et Alexa la nomme
        $this->assertSame('Marché', $result->listName);
        $this->assertSame(4.0, $result->remaining);
        $this->assertEquals(['oeuf' => 4.0], $this->s->rows('market-list'));
    }

    public function testAYolkIsNeverRemovedFromAListSentToTheDrive(): void
    {
        // Given : la liste qui porte le jaune est partie au drive
        $list = $this->listWith(3, ['jaune' => 2.0]);
        $list->send(new DateTimeImmutable());
        $this->s->lists->save($list);

        // When
        $result = $this->remove($this->s->yolk->getSlug());

        // Then : elle n'est ni lue ni modifiée
        $this->assertFalse($result->found);
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0)], $this->s->reload()->getPartShares());
        $this->assertEquals(['oeuf' => 5.0], $this->s->rows());
    }

    public function testTheListRegistryDecidesNotWhatTheIngredientIsToday(): void
    {
        // Given : la liste a gardé 2 jaunes, mais le jaune a été recollé depuis (il redevient un ingrédient ordinaire)
        $this->listWith(3, ['jaune' => 2.0]);
        $this->s->ingredients->findOneBySlug($this->s->yolk->getSlug())->detachFromParent();

        // When
        $result = $this->remove($this->s->yolk->getSlug());

        // Then : le retrait suit la liste : un jaune de moins, rien n'est perdu ni laissé
        $this->assertSame('Œuf', $result->foldedInto);
        $this->assertSame(4.0, $result->remaining);
        $this->assertEquals(['jaune' => new PartShare('oeuf', 1.0, 1.0)], $this->s->reload()->getPartShares());
    }
}

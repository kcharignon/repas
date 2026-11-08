<?php

namespace Repas\Tests\Voice\InputAdapter;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Model\User;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemCommand;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQuery;
use Repas\Voice\Application\Query\GetSpokenItemInList\SpokenItemInListDTO;

/**
 * Dire « ajoute un jaune d'œuf », « enlève un jaune d'œuf » et « est-ce qu'il y a des jaunes d'œuf ? » (ADR 0038 décisions 11 et 33, SUB-005), de bout en
 * bout sur la vraie base : les vrais bus, le vrai classeur d'ingrédients, les vrais Open Host Services de `Repas`, la vraie liste. « Jaune d'œuf » et
 * « Blanc d'œuf » sont des PARTIES d'« Œuf » : 3 œufs + 1 jaune font 4 œufs, jamais une ligne de jaune.
 */
class SpokenPartsFlowTest extends DatabaseTestCase
{
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private ShoppingListRepository $lists;
    private DataPersister $persister;
    private User $user;
    private Ingredient $egg;
    private Ingredient $yolk;
    private Ingredient $white;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->commands = $container->get(CommandBusInterface::class);
        $this->queries = $container->get(QueryBusInterface::class);
        $this->lists = $container->get(ShoppingListRepository::class);
        $this->persister = new DataPersister($container);
        $this->user = new UserBuilder()->build();
        $this->persister->saveUser($this->user);
        $this->egg = $this->saved(new IngredientBuilder()->isEgg()->withCreator($this->user, true)->build());
        $this->yolk = $this->saved(new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('jaune-d-oeuf')->withCreator($this->user, true)->withParent($this->egg->getSlug(), 1.0)->build());
        $this->white = $this->saved(new IngredientBuilder()->isEgg()->withName("Blanc d'œuf")->withSlug('blanc-d-oeuf')->withCreator($this->user, true)->withParent($this->egg->getSlug(), 1.0)->build());
    }

    private function saved(Ingredient $ingredient): Ingredient
    {
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /** Une liste en cours qui porte déjà `$eggs` œufs entiers (ajoutés à la main). */
    private function savedListWithEggs(int $eggs): ShoppingList
    {
        $list = new ShoppingListBuilder()->withOwner($this->user)->build();
        $list->addIngredient($this->egg->getSlug(), 'unite', $eggs);
        $list->addRow($this->egg->getSlug(), 'unite', $eggs);
        $this->lists->save($list);
        $this->entityManager->clear();

        return $this->lists->findOneById($list->getId());
    }

    private function say(string $phrase, int $quantity = 1, bool $open = false): AddSpokenItemResult
    {
        $result = $this->commands->dispatch(new AddSpokenItemCommand($this->user->getId(), $phrase, $open, quantity: $quantity));
        $this->assertInstanceOf(AddSpokenItemResult::class, $result);
        $this->entityManager->clear();

        return $result;
    }

    private function remove(string $phrase, int $quantity = 1): AddSpokenItemResult
    {
        $result = $this->commands->dispatch(new RemoveSpokenItemCommand($this->user->getId(), $phrase, false, quantity: $quantity));
        $this->assertInstanceOf(AddSpokenItemResult::class, $result);
        $this->entityManager->clear();

        return $result;
    }

    private function ask(string $phrase): string
    {
        $answer = $this->queries->ask(new GetSpokenItemInListQuery($this->user->getId(), $phrase, false));
        $this->assertInstanceOf(SpokenItemInListDTO::class, $answer);

        return $answer->speech;
    }

    /** @return array<string, float> les lignes de la liste en cours, par ingrédient */
    private function rows(string $listId): array
    {
        $rows = [];
        foreach ($this->lists->findOneById($listId)->getRows() as $row) {
            $rows[$row->getIngredientSlug()] = $row->getQuantity();
        }

        return $rows;
    }

    public function testTheOwnersScenarioThreeEggsThenYolksAndWhitesThenRemovingAndAsking(): void
    {
        // Given : « 3 œufs » dans la liste
        $list = $this->savedListWithEggs(3);

        // When / Then : « ajoute un jaune d'œuf » : 3 œufs entiers + 1 jaune = 4
        $this->assertSame("C'est ajouté. Ça fait 4 œufs dans ta liste.", $this->say("un jaune d'œuf")->speech);
        $this->assertSame([$this->egg->getSlug() => 4.0], $this->rows($list->getId()));

        // And : « ajoute un blanc d'œuf » : le blanc complète le jaune, le total ne bouge pas
        $this->assertSame("C'est ajouté. Ça fait 4 œufs dans ta liste.", $this->say("un blanc d'œuf")->speech);
        $this->assertSame([$this->egg->getSlug() => 4.0], $this->rows($list->getId()));

        // And : « ajoute trois jaunes d'œuf » : 3 œufs + 4 jaunes = 7
        $this->assertSame("C'est ajouté. Ça fait 7 œufs dans ta liste.", $this->say("jaunes d'œuf", 3)->speech);
        $this->assertSame([$this->egg->getSlug() => 7.0], $this->rows($list->getId()));
        $registry = $this->lists->findOneById($list->getId())->getPartShares();
        $this->assertEquals([$this->yolk->getSlug() => new PartShare($this->egg->getSlug(), 4.0, 4.0), $this->white->getSlug() => new PartShare($this->egg->getSlug(), 1.0, 1.0)], $registry);

        // And : « est-ce qu'il y a des jaunes d'œuf ? » : 4 jaunes, qui comptent dans les 7 œufs ; une lecture seule
        $version = $this->lists->findOneById($list->getId())->getVersion();
        $this->assertSame("Oui : 4 jaunes d'œuf. Ça compte dans tes 7 œufs.", $this->ask("des jaunes d'œuf"));
        $this->assertSame("Oui : 1 blanc d'œuf. Ça compte dans tes 7 œufs.", $this->ask("un blanc d'œuf"));
        $this->assertSame('Oui, tu en as 7.', $this->ask('des œufs'));
        $this->assertSame($version, $this->lists->findOneById($list->getId())->getVersion());

        // And : « enlève un jaune d'œuf » : 3 jaunes, 6 œufs
        $this->assertSame("C'est retiré. Il te reste 6 œufs.", $this->remove("un jaune d'œuf")->speech);
        $this->assertSame([$this->egg->getSlug() => 6.0], $this->rows($list->getId()));

        // And : « enlève dix jaunes d'œuf » : tous les jaunes partent ; les 3 œufs entiers restent, et le blanc qui reste compte pour un œuf de plus
        $this->assertSame("C'est retiré. Il te reste 4 œufs.", $this->remove("jaunes d'œuf", 10)->speech);
        $this->assertSame([$this->egg->getSlug() => 4.0], $this->rows($list->getId()));
        $this->assertSame([$this->white->getSlug()], array_keys($this->lists->findOneById($list->getId())->getPartShares()));
    }

    public function testAYolkThatIsNotInTheListIsAbsentEvenWithEggsAndNothingIsWritten(): void
    {
        // Given : 3 œufs entiers, aucun jaune
        $list = $this->savedListWithEggs(3);
        $version = $list->getVersion();

        // When / Then
        $this->assertSame("Il n'y a pas de jaune d'œuf dans ta liste.", $this->remove("un jaune d'œuf")->speech);
        $this->assertSame("Non, il n'y a pas de jaune d'œuf dans ta liste.", $this->ask("un jaune d'œuf"));
        $this->assertSame($version, $this->lists->findOneById($list->getId())->getVersion());
        $this->assertSame([$this->egg->getSlug() => 3.0], $this->rows($list->getId()));
    }

    public function testRemovingTheWholeParentErasesTheYolksWithIt(): void
    {
        // Given : 3 œufs + 1 jaune (4 œufs)
        $list = $this->savedListWithEggs(3);
        $this->say("un jaune d'œuf");

        // When : « enlève cinq œufs »
        $answer = $this->remove('œufs', 5);

        // Then : l'œuf est un ingrédient ordinaire, la ligne a disparu avec ses parties
        $this->assertSame("C'est retiré : 4 œufs. Il n'y en a plus dans ta liste.", $answer->speech);
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertSame([[], []], [$this->rows($list->getId()), $reloaded->getPartShares()]);
        $this->assertSame("Non, il n'y a pas de jaune d'œuf dans ta liste.", $this->ask("un jaune d'œuf"));
    }

    public function testAYolkAloneInAListMakesOneEggAndItsRemovalEmptiesTheLine(): void
    {
        // Given : une liste vide
        $list = new ShoppingListBuilder()->withOwner($this->user)->build();
        $this->lists->save($list);
        $this->entityManager->clear();

        // When / Then
        $this->assertSame("C'est ajouté. Ça fait 1 œuf dans ta liste.", $this->say("un jaune d'œuf", open: true)->speech);
        $this->assertSame([$this->egg->getSlug() => 1.0], $this->rows($list->getId()));
        $this->assertSame("Oui : 1 jaune d'œuf. Ça fait 1 œuf dans ta liste.", $this->ask("un jaune d'œuf"));
        $this->assertSame("C'est retiré. Il n'y a plus d'œuf dans ta liste.", $this->remove("un jaune d'œuf")->speech);
        $this->assertSame([[], []], [$this->rows($list->getId()), $this->lists->findOneById($list->getId())->getPartShares()]);
    }

    public function testAListSentToTheDriveIsNeitherReadNorModified(): void
    {
        // Given : 3 œufs et 2 jaunes dans une liste partie au drive
        $list = $this->savedListWithEggs(3);
        $list->addPart($this->yolk->getSlug(), $this->egg->getSlug(), 'unite', 2.0, 2.0);
        $list->send(new \DateTimeImmutable());
        $this->lists->save($list);
        $this->entityManager->clear();
        $version = $this->lists->findOneById($list->getId())->getVersion();

        // When / Then : Alexa ne la voit pas, ne la retire pas, et un ajout démarre une liste neuve
        $this->assertSame('Ta liste est déjà partie au drive.', $this->remove("un jaune d'œuf")->speech);
        $this->assertSame('Ta liste est déjà partie au drive.', $this->ask("un jaune d'œuf"));
        $added = $this->say("un jaune d'œuf");
        $this->assertSame("J'ai commencé une nouvelle liste avec jaune d'œuf. Ta liste précédente est partie au drive.", $added->speech);
        $sent = $this->lists->findOneById($list->getId());
        $this->assertSame([ShoppingListStatus::SENT, $version], [$sent->getStatus(), $sent->getVersion()]);
        $this->assertEquals([$this->yolk->getSlug() => new PartShare($this->egg->getSlug(), 2.0, 2.0)], $sent->getPartShares());
    }

    public function testTheYolkOfAnotherUserIsNeverFoundNorWrittenTo(): void
    {
        // Given : un autre utilisateur avec un jaune d'œuf dans sa liste
        $other = new UserBuilder()->build();
        $this->persister->saveUser($other);
        $otherEgg = $this->saved(new IngredientBuilder()->isEgg()->withSlug('oeuf-autre')->withCreator($other, true)->build());
        $this->saved(new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('jaune-autre')->withCreator($other, true)->withParent($otherEgg->getSlug(), 1.0)->build());
        $theirs = new ShoppingListBuilder()->withOwner($other)->build();
        $theirs->addPart('jaune-autre', 'oeuf-autre', 'unite', 2.0, 2.0);
        $this->lists->save($theirs);
        $this->entityManager->clear();
        $this->savedListWithEggs(1);

        // When / Then : je n'ai pas de jaune dans MA liste, la sienne n'est ni lue ni touchée
        $this->assertSame("Non, il n'y a pas de jaune d'œuf dans ta liste.", $this->ask("un jaune d'œuf"));
        $this->assertSame("Il n'y a pas de jaune d'œuf dans ta liste.", $this->remove("un jaune d'œuf")->speech);
        $this->assertEquals(['jaune-autre' => new PartShare('oeuf-autre', 2.0, 2.0)], $this->lists->findOneById($theirs->getId())->getPartShares());
    }
}

<?php

namespace Repas\Tests\Repas\OutputAdapter;

use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\RowCheckSource;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class ShoppingListRowRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRowRepository $shoppingListRowRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRowRepository = static::getContainer()->get(ShoppingListRowRepository::class);

        // Les lignes de test portent des œufs, en unité : on les enregistre,
        $persister = new DataPersister(static::getContainer());
        $persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $persister->saveUnit(new UnitBuilder()->isUnite()->build());
    }


    public function testAllRowsCheckedIsFalseWhileOneRowRemainsToTick(): void
    {
        // Arrange
        $shoppingListId = 'shopping-list-id';
        $checked = new ShoppingListRowBuilder()->withShoppingListId($shoppingListId)->checked()->build();
        $remaining = new ShoppingListRowBuilder()->withShoppingListId($shoppingListId)->build();
        $this->shoppingListRowRepository->save($checked);
        $this->shoppingListRowRepository->save($remaining);

        // Act & Assert
        $this->assertFalse($this->shoppingListRowRepository->allRowsChecked($shoppingListId));

        // Arrange
        $remaining->tick(new \DateTimeImmutable());
        $this->shoppingListRowRepository->save($remaining);

        // Act & Assert
        $this->assertTrue($this->shoppingListRowRepository->allRowsChecked($shoppingListId));
    }

    public function testAllRowsCheckedIgnoresTheRowsOfOtherListsAndIsTrueForAnEmptyList(): void
    {
        // Arrange
        $this->shoppingListRowRepository->save(new ShoppingListRowBuilder()->withShoppingListId('another-list')->build());

        // Act & Assert
        $this->assertTrue($this->shoppingListRowRepository->allRowsChecked('shopping-list-id'));
    }

    /**
     * @param list<array{0: string, 1: string, 2?: bool}> $rows liste, ingrédient, coché
     */
    private function saveRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->shoppingListRowRepository->save(ShoppingListRow::load([
                'id' => "$row[0]-$row[1]", 'shopping_list_id' => $row[0], 'ingredient_slug' => $row[1], 'quantity' => 1.0, 'unit_slug' => 'unite', 'checked' => $row[2] ?? false,
            ]));
        }
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    /**
     * @return array<string, array<string, mixed>> les colonnes du journal de chaque ligne, par identifiant, lues en SQL (jamais par le modèle)
     */
    private function journal(): array
    {
        $rows = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->fetchAllAssociative(
            'SELECT id, checked, checked_by, checked_at, drive_outcome, drive_reason, drive_attempted_at FROM shopping_list_row ORDER BY id',
        );

        return array_column($rows, null, 'id');
    }

    public function testRecordDrivePassageChecksTheLinesInTheCartAndKeepsTheOutcomeOfTheOthersInOneStatement(): void
    {
        // Given (DRV-745, ADR 0041) : deux listes ; la première a un œuf, un lait, un sel, une farine et une pâte (déjà cochée par la personne), la seconde un œuf
        $this->saveRows([['list-a', 'oeuf'], ['list-a', 'lait'], ['list-a', 'sel'], ['list-a', 'farine'], ['list-a', 'pate', true], ['list-b', 'oeuf']]);
        $this->shoppingListRowRepository->save(ShoppingListRow::load([
            'id' => 'list-a-pate', 'shopping_list_id' => 'list-a', 'ingredient_slug' => 'pate', 'quantity' => 1.0, 'unit_slug' => 'unite', 'checked' => true,
            'checked_by' => RowCheckSource::MANUAL, 'checked_at' => new \DateTimeImmutable('2026-11-18 08:00:00'),
        ]));
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : l'œuf et la pâte sont au panier, le lait est refusé, le sel en erreur, un ingrédient absent de la liste est ignoré
        $this->shoppingListRowRepository->recordDrivePassage('list-a', [
            new DriveLineAttempt('oeuf', DriveLineOutcome::IN_CART),
            new DriveLineAttempt('pate', DriveLineOutcome::IN_CART),
            new DriveLineAttempt('lait', DriveLineOutcome::NOT_IN_CART),
            new DriveLineAttempt('sel', DriveLineOutcome::FAILED, 'BLOCKED'),
            new DriveLineAttempt('cumin', DriveLineOutcome::SKIPPED),
        ], new \DateTimeImmutable('2026-11-19 10:00:00'));

        // Then : une seule instruction
        $this->assertSame(['UPDATE shopping_list_row' => 1], $counter->byTable());
        $journal = $this->journal();
        // l'œuf : au panier, coché par le drive à l'instant du passage
        $this->assertSame([true, 'DRIVE', '2026-11-19 10:00:00', 'IN_CART', null, '2026-11-19 10:00:00'], [$journal['list-a-oeuf']['checked'], $journal['list-a-oeuf']['checked_by'], $journal['list-a-oeuf']['checked_at'], $journal['list-a-oeuf']['drive_outcome'], $journal['list-a-oeuf']['drive_reason'], $journal['list-a-oeuf']['drive_attempted_at']]);
        // la pâte : déjà cochée à la main, elle garde son origine et son instant, mais garde aussi que le drive l'a vue
        $this->assertSame([true, 'MANUAL', '2026-11-18 08:00:00', 'IN_CART'], [$journal['list-a-pate']['checked'], $journal['list-a-pate']['checked_by'], $journal['list-a-pate']['checked_at'], $journal['list-a-pate']['drive_outcome']]);
        // le lait refusé et le sel en erreur : décochés, avec leur issue et leur raison
        $this->assertSame([false, null, null, 'NOT_IN_CART', null, '2026-11-19 10:00:00'], [$journal['list-a-lait']['checked'], $journal['list-a-lait']['checked_by'], $journal['list-a-lait']['checked_at'], $journal['list-a-lait']['drive_outcome'], $journal['list-a-lait']['drive_reason'], $journal['list-a-lait']['drive_attempted_at']]);
        $this->assertSame([false, 'FAILED', 'BLOCKED'], [$journal['list-a-sel']['checked'], $journal['list-a-sel']['drive_outcome'], $journal['list-a-sel']['drive_reason']]);
        // la farine n'a pas été rapportée, l'œuf de l'autre liste non plus : rien n'a bougé
        $this->assertSame([false, null, null, null, null], [$journal['list-a-farine']['checked'], $journal['list-a-farine']['checked_by'], $journal['list-a-farine']['drive_outcome'], $journal['list-a-farine']['drive_reason'], $journal['list-a-farine']['drive_attempted_at']]);
        $this->assertSame([false, null, null], [$journal['list-b-oeuf']['checked'], $journal['list-b-oeuf']['checked_by'], $journal['list-b-oeuf']['drive_outcome']]);
    }

    public function testRecordDrivePassageNeverUnchecksAndSendsNothingForNoAttempt(): void
    {
        // Given : une ligne cochée par le drive à un premier passage
        $this->saveRows([['list-a', 'oeuf']]);
        $this->shoppingListRowRepository->recordDrivePassage('list-a', [new DriveLineAttempt('oeuf', DriveLineOutcome::IN_CART)], new \DateTimeImmutable('2026-11-19 10:00:00'));
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : aucun passage, puis un second où le même article n'est plus au panier
        $this->shoppingListRowRepository->recordDrivePassage('list-a', [], new \DateTimeImmutable('2026-11-19 11:00:00'));
        $this->shoppingListRowRepository->recordDrivePassage('list-a', [new DriveLineAttempt('oeuf', DriveLineOutcome::NOT_IN_CART)], new \DateTimeImmutable('2026-11-19 11:00:00'));

        // Then : une seule instruction pour deux appels ; la ligne reste cochée (jamais de décochage), le dernier passage a remplacé l'issue
        $this->assertSame(['UPDATE shopping_list_row' => 1], $counter->byTable());
        $row = $this->journal()['list-a-oeuf'];
        $this->assertSame([true, 'DRIVE', '2026-11-19 10:00:00', 'NOT_IN_CART', '2026-11-19 11:00:00'], [$row['checked'], $row['checked_by'], $row['checked_at'], $row['drive_outcome'], $row['drive_attempted_at']]);
    }

    public function testASavedListKeepsTheDriveOutcomeOfItsRows(): void
    {
        // Given : une ligne que le drive a refusée
        $this->saveRows([['list-a', 'oeuf']]);
        $this->shoppingListRowRepository->recordDrivePassage('list-a', [new DriveLineAttempt('oeuf', DriveLineOutcome::NOT_IN_CART)], new \DateTimeImmutable('2026-11-19 10:00:00'));
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // When : la liste est rouverte, la personne coche la ligne à la main (le modèle est relu puis enregistré, comme tout enregistrement de liste)
        $row = $this->shoppingListRowRepository->findOneById('list-a-oeuf');
        $row->tick(new \DateTimeImmutable('2026-11-19 12:00:00'));
        $this->shoppingListRowRepository->save($row);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Then : cochée à la main APRÈS le refus du drive, et le refus est toujours là : c'est ce qu'on veut voir
        $journal = $this->journal()['list-a-oeuf'];
        $this->assertSame([true, 'MANUAL', '2026-11-19 12:00:00', 'NOT_IN_CART', '2026-11-19 10:00:00'], [$journal['checked'], $journal['checked_by'], $journal['checked_at'], $journal['drive_outcome'], $journal['drive_attempted_at']]);

        // When : puis décochée
        $row = $this->shoppingListRowRepository->findOneById('list-a-oeuf');
        $row->uncheck();
        $this->shoppingListRowRepository->save($row);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Then : l'origine de la coche s'efface avec elle
        $journal = $this->journal()['list-a-oeuf'];
        $this->assertSame([false, null, null, 'NOT_IN_CART'], [$journal['checked'], $journal['checked_by'], $journal['checked_at'], $journal['drive_outcome']]);
    }

    public function testCRUD(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow = new ShoppingListRowBuilder()
            ->withShoppingListId($shoppingList->getId())
            ->build();

        // Act
        $this->shoppingListRowRepository->save($shoppingListRow);

        // Assert
        $actual = $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
        RepasAssert::assertShoppingListRow($shoppingListRow, $actual);

        // Arrange
        $shoppingListRow->addQuantity(10);

        // Act
        $this->shoppingListRowRepository->save($shoppingListRow);

        // Assert
        $actual = $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
        RepasAssert::assertShoppingListRow($shoppingListRow, $actual);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListId($shoppingList->getId());
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $this->expectException(\Exception::class); // Adapte si une exception spécifique est levée
        $this->shoppingListRowRepository->findOneById($shoppingListRow->getId());
    }

    public function testFindByShoppingListId(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);

        // Act
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());

        // Assert
        $this->assertCount(2, $rows);
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingListRow::class), $rows);
    }

    public function testDeleteByShoppingListIdExceptIds(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow3 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);
        $this->shoppingListRowRepository->save($shoppingListRow3);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListIdExceptIds(
            $shoppingList->getId(),
            Tab::fromArray([$shoppingListRow1->getId(), $shoppingListRow2->getId()])
        );

        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(ShoppingListRow::class, $rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow1->getId()));
        $this->assertInstanceOf(ShoppingListRow::class, $rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow2->getId()));
        $this->assertNull($rows->find(fn(ShoppingListRow $row) => $row->getId() === $shoppingListRow3->getId()));
    }

    public function testDeleteByShoppingListId(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->build();
        $shoppingListRow1 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();
        $shoppingListRow2 = new ShoppingListRowBuilder()->withShoppingListId($shoppingList->getId())->build();

        $this->shoppingListRowRepository->save($shoppingListRow1);
        $this->shoppingListRowRepository->save($shoppingListRow2);

        // Act
        $this->shoppingListRowRepository->deleteByShoppingListId($shoppingList->getId());
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        // Assert
        $rows = $this->shoppingListRowRepository->findByShoppingListId($shoppingList->getId());
        $this->assertCount(0, $rows);
    }
}

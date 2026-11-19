<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Interface\ShoppingListArchiveRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les archives des listes supprimées (ADR 0041) sur la VRAIE base : la copie est à plat (la liste, ses lignes et ses repas avec leurs noms), elle se lit sans
 * jointure, elle survit à la liste et se supprime avec le compte.
 */
class ShoppingListArchiveRepositoryTest extends DatabaseTestCase
{
    private ShoppingListArchiveRepository $archiveRepository;
    private ShoppingListRepository $shoppingListRepository;
    private ShoppingListRowRepository $rowRepository;
    private DataPersister $persister;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->archiveRepository = $container->get(ShoppingListArchiveRepository::class);
        $this->shoppingListRepository = $container->get(ShoppingListRepository::class);
        $this->rowRepository = $container->get(ShoppingListRowRepository::class);
        $this->persister = new DataPersister($container);
        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheArchiveCopiesTheListItsRowsAndItsMealsWithTheirNamesAndTheJournalOfEachRow(): void
    {
        // Given : une liste envoyée au drive qui planifie des pâtes carbonara ; l'œuf est refusé par le drive, la pâte est mise au panier, la crème cochée à la main
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());
        $list = $this->savedListOfTheRecipe($owner, $recipe, 'Courses du samedi');
        $list->send(new DateTimeImmutable('2026-11-19 09:00:00'));
        $this->shoppingListRepository->save($list);
        $slugs = array_map(static fn($row): string => $row->getIngredientSlug(), array_values($list->getRows()->toArray()));
        $this->assertGreaterThanOrEqual(3, count($slugs));
        [$refused, $inCart] = [$slugs[0], $slugs[1]];
        $this->rowRepository->recordDrivePassage($list->getId(), [
            new DriveLineAttempt($refused, DriveLineOutcome::NOT_IN_CART),
            new DriveLineAttempt($inCart, DriveLineOutcome::IN_CART),
        ], new DateTimeImmutable('2026-11-19 10:00:00'));
        $this->entityManager->clear();

        // When
        $this->archiveRepository->archive('archive-1', $list->getId(), ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));

        // Then : une archive, à plat
        $archive = $this->connection->fetchAssociative('SELECT * FROM shopping_list_archive');
        $this->assertSame(
            ['archive-1', $list->getId(), $owner->getId(), 'Courses du samedi', 'SENT', 'DELETED_BY_USER', '2026-11-19 11:00:00', '2026-11-19 09:00:00'],
            [$archive['id'], $archive['shopping_list_id'], $archive['owner_id'], $archive['name'], $archive['status'], $archive['cause'], $archive['archived_at'], $archive['sent_at']],
        );
        $this->assertSame(count($slugs), (int) $archive['line_count']);

        // les lignes portent le nom de l'ingrédient et le symbole de l'unité (aucune jointure à faire), et le journal de chaque ligne
        $lines = json_decode($archive['lines'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(count($slugs), $lines);
        $bySlug = array_column($lines, null, 'ingredient_slug');
        $this->assertNotSame('', (string) $bySlug[$refused]['ingredient_name']);
        $this->assertNotNull($bySlug[$refused]['unit_symbol']);
        $this->assertSame(['NOT_IN_CART', false, null], [$bySlug[$refused]['drive_outcome'], $bySlug[$refused]['checked'], $bySlug[$refused]['checked_by']]);
        $this->assertSame(['IN_CART', true, 'DRIVE', '2026-11-19T10:00:00'], [$bySlug[$inCart]['drive_outcome'], $bySlug[$inCart]['checked'], $bySlug[$inCart]['checked_by'], $bySlug[$inCart]['checked_at']]);

        // les repas portent le nom de la recette
        $meals = json_decode($archive['meals'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([[$recipe->getId(), $recipe->getName(), $recipe->getServing()]], array_map(static fn(array $meal): array => [$meal['recipe_id'], $meal['recipe_name'], $meal['serving']], $meals));
    }

    public function testTheArchiveSurvivesTheListAndItsNamesDoNotNeedTheOtherTablesAnymore(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());
        $list = $this->savedListOfTheRecipe($owner, $recipe);
        $this->shoppingListRepository->save($list);
        $this->archiveRepository->archive('archive-1', $list->getId(), ShoppingListArchiveCause::CLEANED_AFTER_COMPLETION, new DateTimeImmutable('2026-11-19 11:00:00'));

        // When : la liste est supprimée, puis l'ingrédient et la recette qu'elle citait
        $this->shoppingListRepository->delete($this->shoppingListRepository->findOneById($list->getId()));
        $this->connection->executeStatement('DELETE FROM meal');
        $this->connection->executeStatement('DELETE FROM recipe');

        // Then : l'archive se lit toute seule, sans aucune jointure
        $archive = $this->connection->fetchAssociative('SELECT cause, lines, meals FROM shopping_list_archive');
        $this->assertSame('CLEANED_AFTER_COMPLETION', $archive['cause']);
        $lines = json_decode($archive['lines'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($lines);
        $this->assertNotSame('', (string) $lines[0]['ingredient_name']);
        $meals = json_decode($archive['meals'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($recipe->getName(), $meals[0]['recipe_name']);
    }

    public function testAListWithoutRowNorMealIsArchivedWithEmptyTables(): void
    {
        // Given
        $owner = $this->savedUser();
        $list = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::COMPLETED)->build();
        $this->shoppingListRepository->save($list);

        // When
        $this->archiveRepository->archive('archive-1', $list->getId(), ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));

        // Then
        $archive = $this->connection->fetchAssociative('SELECT status, line_count, lines, meals, part_shares, list_type_id, list_type_name FROM shopping_list_archive');
        $this->assertSame(['COMPLETED', 0, '[]', '[]', null, null, null], [$archive['status'], (int) $archive['line_count'], $archive['lines'], $archive['meals'], $archive['part_shares'], $archive['list_type_id'], $archive['list_type_name']]);
    }

    public function testAnUnknownListWritesNothingAndTheArchiveCostsOneStatement(): void
    {
        // Given
        $owner = $this->savedUser();
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        $this->shoppingListRepository->save($list);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->archiveRepository->archive('archive-ghost', 'does-not-exist', ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));
        $this->archiveRepository->archive('archive-1', $list->getId(), ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));

        // Then : deux instructions (une par appel), une seule archive
        $this->assertSame(['INSERT shopping_list_archive' => 2], $counter->byTable());
        $this->assertSame(['archive-1'], $this->connection->fetchFirstColumn('SELECT id FROM shopping_list_archive'));
    }

    public function testDeleteByOwnerErasesTheArchivesOfThatUserOnly(): void
    {
        // Given : une archive de chacun
        $leaving = $this->savedUser();
        $staying = $this->savedUser();
        foreach ([[$leaving, 'archive-leaving'], [$staying, 'archive-staying']] as [$owner, $archiveId]) {
            $list = new ShoppingListBuilder()->withOwner($owner)->build();
            $this->shoppingListRepository->save($list);
            $this->archiveRepository->archive($archiveId, $list->getId(), ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));
        }

        // When
        $this->archiveRepository->deleteByOwner($leaving->getId());

        // Then
        $this->assertSame(['archive-staying'], $this->connection->fetchFirstColumn('SELECT id FROM shopping_list_archive'));
    }

    /** Une liste du propriétaire qui planifie la recette, avec une ligne par ingrédient de la recette (le builder ne pose que le repas). */
    private function savedListOfTheRecipe(User $owner, Recipe $recipe, ?string $name = null): ShoppingList
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->withName($name)->addRecipe($recipe)->build();
        foreach ($recipe->getRows() as $row) {
            $list->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
        }

        return $list;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }
}

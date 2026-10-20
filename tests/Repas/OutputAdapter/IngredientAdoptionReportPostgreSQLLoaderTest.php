<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientAdoptionReportLoader;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionUserReportDTO;
use Repas\Repas\Infrastructure\Loader\IngredientAdoptionReportPostgreSQLLoader;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le rapport « à blanc » de la migration d'adoption (ING-003) sur la vraie base : ce qu'il compte, ce qu'il signale, et qu'il n'écrit
 * RIEN (aucune table ne change). Les nombres attendus sont ceux du jeu `AdoptionDataset`, comptés à la main à partir de sa description.
 */
class IngredientAdoptionReportPostgreSQLLoaderTest extends DatabaseTestCase
{
    private IngredientAdoptionReportLoader $loader;
    private AdoptionDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientAdoptionReportLoader::class);
        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheReportCountsWhatWouldBeCopiedAndRepointedForEachAccountAndWritesNothing(): void
    {
        // Given
        $before = $this->dataset->tableHashes();

        // When
        $report = $this->loader->report([], []);

        // Then : le catalogue, les comptes dans l'ordre de leur identifiant (donc numérotés de 1 à 6)
        $this->assertSame([12, 3, 15], [$report->commons, $report->commonOwnConversions, $report->longestCommonSlug]);
        $this->assertSame([], $report->catalogAnomalies);
        $this->assertSame([], $report->unknownSelectors);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(static fn(AdoptionUserReportDTO $u): int => $u->rank, $report->users));
        $this->assertSame(
            [$this->dataset->bigId, $this->dataset->copierId, $this->dataset->singleId, $this->dataset->emptyId, $this->dataset->adminId, $this->dataset->disabledId],
            array_map(static fn(AdoptionUserReportDTO $u): string => $u->userId, $report->users),
        );
        $users = array_column(array_map(static fn(AdoptionUserReportDTO $u): array => [$u->userId, $u], $report->users), 1, 0);

        // Le gros compte
        $big = $users[$this->dataset->bigId];
        $this->assertTrue($big->selected);
        $this->assertNull($big->exclusion);
        $this->assertSame(['ACTIVE', 6, 0, 20, 0], [$big->status, $big->personalIngredients, $big->adoptedCopies, $big->recipes, $big->copiedRecipes]);
        $this->assertSame([12, 0, 1, ['Tomate'], 3], [$big->toAdopt, $big->alreadyAdopted, $big->slugCollisions, $big->collidingCommons, $big->conversionsToCopy]);
        $this->assertSame([60, 20], [$big->recipeRowsToRepoint, $big->recipesToRepoint]);
        $this->assertSame([12, 4, 4, 1], [$big->shoppingListRowsToRepoint, $big->shoppingListIngredientsToRepoint, $big->shoppingListsToRepoint, $big->sentShoppingListsToRepoint]);
        $this->assertSame([11, 33, 12, 36], [$big->driveChoicesToRepoint, $big->driveProductsOfChoicesToRepoint, $big->driveChoices, $big->driveProducts]);
        $this->assertSame([[], 0], [$big->blockingAnomalies, $big->invalidAliases]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $big->fingerprint);
        $this->assertSame(substr(hash('sha256', $this->dataset->bigId), 0, 8), $big->reference);

        // Les copies seulement, un seul ingrédient personnel, un compte désactivé
        $copier = $users[$this->dataset->copierId];
        $this->assertSame([0, 3, 3, 12, 6, 0], [$copier->personalIngredients, $copier->recipes, $copier->copiedRecipes, $copier->toAdopt, $copier->recipeRowsToRepoint, $copier->driveChoicesToRepoint]);
        $this->assertSame([1, 0, 12, 1, 1], [$users[$this->dataset->singleId]->personalIngredients, $users[$this->dataset->singleId]->recipes, $users[$this->dataset->singleId]->toAdopt, $users[$this->dataset->singleId]->shoppingListRowsToRepoint, $users[$this->dataset->singleId]->driveChoicesToRepoint]);
        $this->assertSame('DISABLED', $users[$this->dataset->disabledId]->status);
        $this->assertTrue($users[$this->dataset->disabledId]->selected);

        // L'administrateur et le compte vide : ignorés, sans détail
        $admin = $users[$this->dataset->adminId];
        $this->assertSame([true, false, false, AdoptionUserReportDTO::ADMINISTRATOR, 0], [$admin->administrator, $admin->concerned, $admin->selected, $admin->exclusion, $admin->toAdopt]);
        $empty = $users[$this->dataset->emptyId];
        $this->assertSame([false, false, AdoptionUserReportDTO::NOTHING_OWNED, 0, 0], [$empty->concerned, $empty->selected, $empty->exclusion, $empty->toAdopt, $empty->shoppingListRowsToRepoint]);

        // Totaux
        $this->assertCount(4, $report->selected());
        $this->assertSame(1, $report->sentShoppingListsToRepoint());
        $this->assertFalse($report->hasBlockingAnomalies());
        $this->assertFalse($report->isNothingToDo());

        // Aucune écriture : aucune ligne d'aucune table n'a bougé
        $this->assertSame($before, $this->dataset->tableHashes());
    }

    public function testSelectionByShortReferenceOrFullIdentifierAndUnknownSelectors(): void
    {
        $dataset = $this->dataset;
        $referenceOfSingle = IngredientAdoptionReportPostgreSQLLoader::referenceOf($dataset->singleId);

        $only = $this->loader->report([$referenceOfSingle, $dataset->copierId], []);
        $this->assertSame([$dataset->copierId, $dataset->singleId], array_map(static fn(AdoptionUserReportDTO $u): string => $u->userId, $only->selected()));
        $this->assertSame(AdoptionUserReportDTO::NOT_SELECTED, $only->users[0]->exclusion);
        $this->assertSame(0, $only->users[0]->toAdopt, 'le détail n\'est calculé que pour les comptes retenus');

        $except = $this->loader->report([], [$dataset->bigId, IngredientAdoptionReportPostgreSQLLoader::referenceOf($dataset->disabledId)]);
        $this->assertSame([$dataset->copierId, $dataset->singleId], array_map(static fn(AdoptionUserReportDTO $u): string => $u->userId, $except->selected()));
        $this->assertSame(AdoptionUserReportDTO::EXCEPTED, $except->users[0]->exclusion);

        $this->assertSame(['ffffffff', 'inconnu'], $this->loader->report(['ffffffff', $referenceOfSingle], ['inconnu', 'ffffffff'])->unknownSelectors);
        // Un administrateur reste ignoré même nommé
        $admin = $this->loader->report([IngredientAdoptionReportPostgreSQLLoader::referenceOf($dataset->adminId)], []);
        $this->assertSame([], $admin->selected());
        $this->assertSame(AdoptionUserReportDTO::ADMINISTRATOR, $admin->users[4]->exclusion);
    }

    public function testAnomaliesAreCountedPerTableWithoutAnyIdentifierAndBlockTheRun(): void
    {
        $dataset = $this->dataset;
        $recipeId = $dataset->connection->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$dataset->copierId]);

        // Given : une ligne de recette dont l'ingrédient n'existe pas, une ligne de liste sur l'ingrédient personnel d'un autre, un choix du drive
        // sur un ingrédient inexistant, et une conversion d'un ingrédient inexistant
        $dataset->insert('recipe_row', ['id' => 'orphan-row', 'ingredient' => 'inexistant', 'quantity' => 1.0, 'unit' => 'gramme', 'recipe' => $recipeId, 'position' => 9]);
        $listId = $dataset->connection->fetchOne("SELECT id FROM shopping_list WHERE owner = ? AND status = 'ACTIVE'", [$dataset->copierId]);
        $dataset->insert('shopping_list_row', ['id' => 'crossed-row', 'shopping_list' => $listId, 'ingredient' => 'praline' . $dataset->bigId, 'quantity' => 1.0, 'unit' => 'gramme', 'checked' => 'false']);
        $dataset->choice($dataset->copierId, 'fantome', '810001', 'leclerc');
        $dataset->choice($dataset->copierId, 'praline' . $dataset->bigId, '810002', 'leclerc');
        $dataset->insert('conversion', ['id' => 'orphan-conversion', 'start_unit' => 'unite', 'end_unit' => 'gramme', 'coefficient' => 1.0, 'ingredient' => 'disparu']);
        $before = $dataset->tableHashes();

        // When
        $report = $this->loader->report([], []);

        // Then
        $copier = array_values(array_filter($report->users, fn(AdoptionUserReportDTO $u): bool => $u->userId === $dataset->copierId))[0];
        $this->assertEqualsCanonicalizing([
            "recettes : 1 ligne(s) dont l'ingrédient n'existe pas",
            "lignes de liste : 1 ligne(s) sur l'ingrédient personnel d'un autre compte",
            "choix du drive : 1 dont l'ingrédient n'existe pas",
            "choix du drive : 1 sur l'ingrédient personnel d'un autre compte",
        ], $copier->blockingAnomalies);
        $this->assertTrue($report->hasBlockingAnomalies());
        $this->assertSame(["1 conversion(s) rattachée(s) à un ingrédient qui n'existe pas"], $report->catalogAnomalies);
        foreach ($copier->blockingAnomalies as $anomaly) {
            $this->assertStringNotContainsString($dataset->bigId, $anomaly);
        }
        // Les autres comptes n'ont pas d'anomalie, et le rapport n'a rien écrit
        $this->assertSame([], array_values(array_filter($report->users, fn(AdoptionUserReportDTO $u): bool => $u->userId !== $dataset->copierId && $u->blockingAnomalies !== [])));
        $this->assertSame($before, $dataset->tableHashes());
    }

    public function testASlugTooLongAndAnInvalidAliasAreAnomalies(): void
    {
        $dataset = $this->dataset;
        $dataset->insert('ingredient', ['slug' => 'enorme', 'name' => str_repeat('a', 250), 'image' => '', 'slugged_name' => 'enorme', 'department' => 'rayon-a', 'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => null, 'compatible_units' => '[]', 'adopted_from' => null]);
        // Un alias d'adoption dont la copie n'existe pas
        $dataset->insert('ingredient_alias', ['user_id' => $dataset->singleId, 'alias_slug' => 'sel', 'ingredient_slug' => 'sel-fantome', 'kind' => 'adoption', 'source' => 'ancien', 'created_at' => '2026-10-01 10:00:00']);

        $report = $this->loader->report([], []);

        $single = array_values(array_filter($report->users, fn(AdoptionUserReportDTO $u): bool => $u->userId === $dataset->singleId))[0];
        $this->assertSame(['1 slug(s) de copie dépasseraient 255 caractères'], $single->blockingAnomalies);
        $this->assertSame(1, $single->invalidAliases);
        $this->assertSame(13, $single->toAdopt, 'l\'alias orphelin n\'est pas une adoption : le sel reste à adopter');
        $this->assertFalse($single->isNothingToDo());
    }

    public function testAfterTheMigrationTheReportSaysThereIsNothingLeftToDoAndKeepsTheFingerprint(): void
    {
        // Given
        $before = $this->loader->report([], []);

        // When
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $tester->execute(['--apply' => true, '--accept-sent' => true]);
        $after = $this->loader->report([], []);

        // Then : rien à faire ; les empreintes de contenu sont celles d'avant, compte par compte
        $this->assertTrue($after->isNothingToDo());
        $this->assertSame(0, $after->sentShoppingListsToRepoint());
        $this->assertSame(
            array_map(static fn(AdoptionUserReportDTO $u): string => $u->fingerprint, $before->users),
            array_map(static fn(AdoptionUserReportDTO $u): string => $u->fingerprint, $after->users),
        );
        foreach ($after->selected() as $user) {
            $this->assertSame([0, 12, 0, 0], [$user->toAdopt, $user->alreadyAdopted, $user->invalidAliases, $user->linesToRepoint()]);
        }
    }
}

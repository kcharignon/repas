<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SeedingDataset;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * Le rapport de l'amorçage (ING-008) sur la VRAIE base : le périmètre par défaut et ses exclusions, les sélections `--user` / `--except` /
 * `--complete`, le détail d'un compte retenu (ce qui serait copié, repointé, les collisions), les anomalies et la requête de santé — le tout en
 * lecture seule.
 */
class IngredientSeedingReportPostgreSQLLoaderTest extends DatabaseTestCase
{
    private IngredientSeedingReportLoader $loader;
    private SeedingDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientSeedingReportLoader::class);
        $this->dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheDefaultScopeIsActiveUnseededAccountsAndEveryOtherAccountSaysWhy(): void
    {
        // When
        $report = $this->loader->report([], [], false);

        // Then
        $this->assertTrue($report->catalogOwnerExists);
        $this->assertSame([12, 8, 3, 15], [$report->catalogIngredients, $report->baseIngredients, $report->baseOwnConversions, $report->longestBaseSlug]);
        $this->assertCount(9, $report->users);
        $this->assertSame(range(1, 9), array_map(static fn(SeedingUserReportDTO $user): int => $user->rank, $report->users));
        $exclusions = [];
        foreach ($report->users as $user) {
            $exclusions[$user->userId] = $user->exclusion;
        }
        $this->assertSame(SeedingUserReportDTO::OWNER, $exclusions[$this->dataset->ownerId]);
        $this->assertSame(SeedingUserReportDTO::NOT_ACTIVE, $exclusions[$this->dataset->adoption->disabledId]);
        $this->assertSame(SeedingUserReportDTO::NOT_ACTIVE, $exclusions[$this->dataset->pendingId]);
        $this->assertSame(
            [$this->dataset->adoption->bigId, $this->dataset->adoption->copierId, $this->dataset->adoption->singleId, $this->dataset->adoption->emptyId, $this->dataset->adoption->adminId, $this->dataset->freshId],
            array_map(static fn(SeedingUserReportDTO $user): string => $user->userId, $report->selected()),
        );
        // La référence est celle du rapport de la migration d'adoption : les 8 premiers caractères du SHA-256 de l'identifiant
        $this->assertSame(substr(hash('sha256', $this->dataset->freshId), 0, 8), $this->userOf($report, $this->dataset->freshId)->reference);
    }

    public function testTheDetailOfARetainedAccountSaysWhatWouldBeCopiedAndRepointed(): void
    {
        // When
        $big = $this->userOf($this->loader->report([], [], false), $this->dataset->adoption->bigId);

        // Then : les huit « de base » et les quatre ingrédients que ses recettes référencent, une collision de slug (« Tomate »), trois conversions propres
        $this->assertSame([12, 8, 4, 0, 1, 3], [$big->toSeed, $big->toSeedBase, $big->toSeedReferenced, $big->reusable, $big->slugCollisions, $big->conversionsToCopy]);
        $this->assertSame([60, 20], [$big->recipeRowsToRepoint, $big->recipesToRepoint]);
        $this->assertSame([12, 4, 4, 1], [$big->shoppingListRowsToRepoint, $big->shoppingListIngredientsToRepoint, $big->shoppingListsToRepoint, $big->sentShoppingListsToRepoint]);
        $this->assertSame(11, $big->driveChoicesToRepoint);
        $this->assertSame([12, 36], [$big->driveChoices, $big->driveProducts]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $big->fingerprint);
        $this->assertSame([], $big->blockingAnomalies);
        $this->assertFalse($big->isNothingToDo());

        // …un compte neuf : huit à copier, rien à repointer
        $fresh = $this->userOf($this->loader->report([], [], false), $this->dataset->freshId);
        $this->assertSame([8, 8, 0, 3], [$fresh->toSeed, $fresh->toSeedBase, $fresh->toSeedReferenced, $fresh->conversionsToCopy]);
        $this->assertSame(0, $fresh->linesToRepoint());
    }

    public function testTheReportIsReadOnly(): void
    {
        // Given
        $before = $this->dataset->adoption->tableHashes();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->report([], [], false);
        $this->loader->report([$this->dataset->adoption->bigId], [], true);

        // Then
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
        $this->assertSame([], array_values(array_filter(
            $counter->statements(),
            static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|\\b(INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+\\w+\\s+SET)\\b|pg_advisory/i', $sql) === 1,
        )));
    }

    public function testUserAndExceptSelectByFullIdentifierOrShortReferenceAndUnknownOnesAreReported(): void
    {
        // Given
        $single = $this->dataset->adoption->singleId;
        $reference = IngredientSeedingReportPostgreSQLLoader::referenceOf($this->dataset->adoption->emptyId);

        // When
        $only = $this->loader->report([$single, $reference], [], false);
        $except = $this->loader->report([], [$single], false);
        $unknown = $this->loader->report(['ffffffff'], ['00000000-dead'], false);

        // Then
        $selected = array_map(static fn(SeedingUserReportDTO $u): string => $u->userId, $only->selected());
        $expected = [$single, $this->dataset->adoption->emptyId];
        sort($selected);
        sort($expected);
        $this->assertSame($expected, $selected);
        $this->assertSame(SeedingUserReportDTO::NOT_SELECTED, $this->userOf($only, $this->dataset->freshId)->exclusion);
        $this->assertSame(SeedingUserReportDTO::EXCEPTED, $this->userOf($except, $single)->exclusion);
        $this->assertSame(5, count($except->selected()));
        $this->assertSame(['ffffffff', '00000000-dead'], $unknown->unknownSelectors);
    }

    public function testUserCanTargetAPendingOrDisabledAccountButNeverTheOwner(): void
    {
        // When
        $report = $this->loader->report([$this->dataset->pendingId, $this->dataset->adoption->disabledId, $this->dataset->ownerId], [], false);

        // Then
        $this->assertSame([$this->dataset->adoption->disabledId, $this->dataset->pendingId], array_map(static fn(SeedingUserReportDTO $u): string => $u->userId, $report->selected()));
        $this->assertSame(SeedingUserReportDTO::OWNER, $this->userOf($report, $this->dataset->ownerId)->exclusion);
    }

    public function testAnAlreadySeededAccountIsExcludedUnlessCompleteIsAskedForIt(): void
    {
        // Given : le compte neuf est amorcé (un alias d'adoption), le propriétaire marque un ingrédient de plus
        $connection = $this->dataset->adoption->connection;
        $fresh = $this->dataset->freshId;
        $this->dataset->adoption->personalIngredient($fresh, 'Beurre', 'rayon-a');
        $connection->insert('ingredient_alias', ['user_id' => $fresh, 'alias_slug' => 'beurre', 'ingredient_slug' => 'beurre' . $fresh, 'kind' => 'adoption', 'source' => 'activation', 'created_at' => date('Y-m-d H:i:s')]);
        $connection->executeStatement("UPDATE ingredient SET adopted_from = 'beurre' WHERE slug = ?", ['beurre' . $fresh]);

        // When
        $default = $this->loader->report([], [], false);
        $complete = $this->loader->report([$fresh], [], true);
        $completeWithoutUser = $this->loader->report([], [], true);

        // Then
        $this->assertSame(SeedingUserReportDTO::ALREADY_SEEDED, $this->userOf($default, $fresh)->exclusion);
        $this->assertTrue($this->userOf($default, $fresh)->seeded);
        $this->assertNull($this->userOf($complete, $fresh)->exclusion);
        $this->assertSame(7, $this->userOf($complete, $fresh)->toSeed, 'tout sauf le beurre qu\'il a déjà');
        $this->assertSame(SeedingUserReportDTO::ALREADY_SEEDED, $this->userOf($completeWithoutUser, $fresh)->exclusion, '--complete sans --user ne retient aucun compte déjà amorcé');
    }

    public function testWhenNothingIsMarkedBaseARetainedAccountHasABlockingAnomalyAndNothingToSeed(): void
    {
        // Given
        $this->dataset->adoption->connection->executeStatement('UPDATE ingredient SET is_base = FALSE');

        // When
        $report = $this->loader->report([], [], false);

        // Then
        $this->assertSame(0, $report->baseIngredients);
        $this->assertTrue($report->hasBlockingAnomalies());
        $fresh = $this->userOf($report, $this->dataset->freshId);
        $this->assertSame(0, $fresh->toSeed);
        $this->assertStringContainsString('aucun ingrédient « de base »', $fresh->blockingAnomalies[0]);
        $this->assertFalse($report->isConform());
    }

    public function testALineOnAnOrphanOrAnotherAccountsPersonalIngredientIsABlockingAnomalyWithoutIdentifier(): void
    {
        // Given : une ligne de recette sans ingrédient, et une ligne sur l'ingrédient personnel d'un autre compte
        $fresh = $this->dataset->freshId;
        $this->dataset->adoption->recipe($fresh, 'Recette d\'essai', ['ingredient-fantome', 'praline' . $this->dataset->adoption->bigId]);

        // When
        $user = $this->userOf($this->loader->report([], [], false), $fresh);

        // Then
        $this->assertSame(["recettes : 1 ligne(s) dont l'ingrédient n'existe pas", "recettes : 1 ligne(s) sur l'ingrédient personnel d'un autre compte"], $user->blockingAnomalies);
        foreach ($user->blockingAnomalies as $anomaly) {
            $this->assertStringNotContainsString($this->dataset->adoption->bigId, $anomaly);
        }
    }

    public function testTheHealthBeforeAnyWriteIsRedAndItCountsWhatIsMissing(): void
    {
        // When
        $health = $this->loader->report([], [], false)->health;

        // Then : six comptes actifs sans amorçage, quatre sans aucun ingrédient, des lignes sur les anciens communs, le propriétaire hors des contrôles
        $this->assertSame(6, $health->activeNotSeeded);
        $this->assertSame(4, $health->activeWithoutIngredient);
        $this->assertSame(['recettes', 'lignes de liste', 'ingrédients de liste', 'choix du drive'], array_keys($health->foreignLines));
        $this->assertNull($health->ownerForeignLines);
        $this->assertSame(12, $health->ingredientsWithoutCreator);
        $this->assertFalse($health->isHealthy());
    }

    public function testTheOwnerEntersTheHealthChecksOnceNoIngredientWithoutCreatorRemains(): void
    {
        // Given : la passation, puis une ligne de recette du propriétaire sur l'ingrédient d'un autre compte
        $this->dataset->handOver();
        $recipeId = $this->dataset->adoption->connection->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$this->dataset->ownerId]);
        $this->dataset->adoption->connection->executeStatement('UPDATE recipe_row SET ingredient = ? WHERE recipe = ? AND position = 0', ['praline' . $this->dataset->adoption->bigId, $recipeId]);

        // When
        $health = $this->loader->report([], [], false)->health;

        // Then : le propriétaire a des ingrédients à lui (les 12), et sa ligne est comptée ; plus aucun ancien commun
        $this->assertSame(0, $health->ingredientsWithoutCreator);
        $this->assertSame(['recettes' => 1], $health->ownerForeignLines);
        $this->assertSame(4, $health->activeWithoutIngredient, 'le propriétaire n\'est pas sans ingrédient');
    }

    public function testWithoutACatalogOwnerTheReportSaysSo(): void
    {
        // Given
        $this->dataset->adoption->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->dataset->ownerId]);

        // When
        $report = $this->loader->report([], [], false);

        // Then
        $this->assertFalse($report->catalogOwnerExists);
        $this->assertContains("le propriétaire du catalogue n'existe pas : rien ne peut être copié", $report->catalogAnomalies);
        $this->assertTrue($report->hasBlockingAnomalies());
        $this->assertSame(0, $this->userOf($report, $this->dataset->freshId)->toSeed);
    }

    private function userOf(SeedingReportDTO $report, string $userId): SeedingUserReportDTO
    {
        foreach ($report->users as $user) {
            if ($user->userId === $userId) {
                return $user;
            }
        }
        $this->fail('Compte absent du rapport.');
    }
}

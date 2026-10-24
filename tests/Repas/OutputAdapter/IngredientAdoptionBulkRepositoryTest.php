<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Les écritures en masse de la migration d'adoption (ING-003) sur la VRAIE base : chaque méthode du port, avec des données de tous les types
 * (`AdoptionDataset`). Les empreintes et les mesures sont calculées indépendamment du code testé.
 */
class IngredientAdoptionBulkRepositoryTest extends DatabaseTestCase
{
    private IngredientAdoptionBulkRepository $repository;
    private AdoptionDataset $dataset;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(IngredientAdoptionBulkRepository::class);
        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        // Le catalogue d'où l'on copie est celui du propriétaire (ici l'administrateur du jeu) : plus d'ingrédient sans créateur
        $this->dataset->connection->executeStatement('UPDATE ingredient SET creator = ? WHERE creator IS NULL', [$this->dataset->adminId]);
        $this->now = new DateTimeImmutable('2026-10-21 03:00:00');
    }

    public function testTheLockIsAnAdvisoryLockOfTheTransactionOnThatUser(): void
    {
        // When
        $this->repository->lock($this->dataset->bigId);
        $this->repository->lock($this->dataset->bigId);
        $this->repository->lock($this->dataset->singleId);

        // Then : un verrou consultatif par utilisateur, tenu jusqu'à la fin de la transaction (celui qui l'a posé le reprend sans attendre)
        $this->assertSame(2, (int) $this->dataset->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    public function testTheTakenSlugsAreThoseThatEndWithTheUserIdentifier(): void
    {
        $taken = $this->repository->takenSlugs($this->dataset->bigId);

        $this->assertContains('tomate' . $this->dataset->bigId, $taken);
        $this->assertCount(6, $taken, 'les six ingrédients personnels, et aucun commun ni ingrédient d\'un autre compte');
        $this->assertSame([], array_filter($taken, fn(string $slug): bool => !str_ends_with($slug, $this->dataset->bigId)));
    }

    public function testWriteAliasesReplacesAnOrphanInPlaceAndNeverRewritesAValidAlias(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->singleId;

        // Given : un alias valide (sel), un alias orphelin (sucre)
        $dataset->insert('ingredient', [...$dataset->connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = 'sel'"), 'slug' => 'sel' . $user, 'creator' => $user, 'adopted_from' => 'sel']);
        $this->insertAlias($user, 'sel', 'sel' . $user, 'ancien-lancement');
        $this->insertAlias($user, 'sucre', 'sucre' . $user, 'ancien-lancement');

        // When
        $written = $this->repository->writeAliases($user, ['sel' => 'sel-2' . $user, 'sucre' => 'sucre' . $user, 'lait' => 'lait' . $user], 'migration-essai', $this->now);

        // Then : le sel valide n'est pas réécrit, le sucre orphelin est remplacé sur place (même clé, aucune suppression), le lait est écrit
        $this->assertEquals(['sucre' => 'sucre' . $user, 'lait' => 'lait' . $user], $written);
        $this->assertSame(
            [['lait', 'lait' . $user, 'adoption', 'migration-essai'], ['sel', 'sel' . $user, 'adoption', 'ancien-lancement'], ['sucre', 'sucre' . $user, 'adoption', 'migration-essai']],
            $dataset->connection->fetchAllNumeric('SELECT alias_slug, ingredient_slug, kind, source FROM ingredient_alias WHERE user_id = ? ORDER BY alias_slug', [$user]),
        );
        $this->assertSame('2026-10-21 03:00:00', $dataset->connection->fetchOne("SELECT created_at FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'lait'", [$user]));
    }

    public function testAMergeAliasOnTheSameKeyIsNeverOverwritten(): void
    {
        // Given : un alias de fusion sur la clé d'un commun (cas anormal)
        $user = $this->dataset->singleId;
        $this->dataset->insert('ingredient_alias', ['user_id' => $user, 'alias_slug' => 'sel', 'ingredient_slug' => 'autre', 'kind' => 'merge', 'source' => 'merge', 'created_at' => '2026-10-01 10:00:00']);

        // When
        $written = $this->repository->writeAliases($user, ['sel' => 'sel' . $user], 'migration-essai', $this->now);

        // Then
        $this->assertSame([], $written);
        $this->assertSame('merge', $this->dataset->connection->fetchOne("SELECT kind FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'sel'", [$user]));
    }

    public function testRepointingChangesOnlyTheLinesOfTheUserAndBumpsOnlyTheVersionsOfWhatChanged(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->copierId;

        // Given : l'adoption complète du compte qui n'a que des recettes copiées, plus une recette et une liste sans aucun commun
        $this->adoptEverything($user);
        $untouchedRecipe = $dataset->recipe($user, 'Sans commun', ['sel' . $user]);
        $untouchedList = $dataset->shoppingList($user, 'ACTIVE', [], []);
        $others = [$dataset->hashOfUser($dataset->adminId), $dataset->hashOfUser($dataset->emptyId), $dataset->hashOfUser($dataset->bigId)];
        $versions = $dataset->connection->fetchAllKeyValue('SELECT id, version FROM recipe WHERE author = ?', [$user]);

        // When
        $repointed = $this->repository->repointToCopies($user);

        // Then : les trois recettes copiées (2 lignes chacune) et la liste (2 lignes) sont repointées, +1 ; la recette et la liste sans commun gardent leur version
        $this->assertSame([6, 3, 2, 0, 1], [$repointed->recipeRows, $repointed->recipes, $repointed->shoppingListRows, $repointed->shoppingListIngredients, $repointed->shoppingLists]);
        $after = $dataset->connection->fetchAllKeyValue('SELECT id, version FROM recipe WHERE author = ?', [$user]);
        foreach ($versions as $id => $version) {
            $this->assertSame($id === $untouchedRecipe ? $version : $version + 1, $after[$id]);
        }
        $this->assertSame(5, (int) $dataset->connection->fetchOne('SELECT version FROM shopping_list WHERE id = ?', [$untouchedList]));
        $this->assertSame([], $dataset->connection->fetchFirstColumn('SELECT x.ingredient FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE r.author = ? AND i.creator IS NULL', [$user]));
        // Les autres comptes n'ont pas bougé d'une ligne
        $this->assertSame($others, [$dataset->hashOfUser($dataset->adminId), $dataset->hashOfUser($dataset->emptyId), $dataset->hashOfUser($dataset->bigId)]);
        // Rejoué, il ne change plus rien
        $this->assertTrue($this->repository->repointToCopies($user)->isEmpty());
    }

    public function testTheSnapshotSeesNamesNotSlugsAndTheWatchedLinesOfSentLists(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->bigId;
        $this->repository->lock($user);

        // When
        $before = $this->repository->snapshot($user, $dataset->commons);
        $this->adoptEverything($user);
        $this->repository->repointToCopies($user);
        $after = $this->repository->snapshot($user, $dataset->commons);

        // Then : les mêmes comptages, sommes et contenus (par le nom) avant et après ; les versions seules ont bougé
        $this->assertSame($before->rowCounts, $after->rowCounts);
        $this->assertSame($before->quantitySums, $after->quantitySums);
        $this->assertSame($before->statistics, $after->statistics);
        $this->assertSame($before->contentFingerprint(), $after->contentFingerprint());
        $this->assertSame(array_map(static fn($r): string => $r->contentHash, $before->recipes), array_map(static fn($r): string => $r->contentHash, $after->recipes));
        $this->assertSame(array_map(static fn($l): string => $l->contentHash . '/' . $l->stateHash, $before->shoppingLists), array_map(static fn($l): string => $l->contentHash . '/' . $l->stateHash, $after->shoppingLists));
        // Avant : 60 lignes de recettes et 12 + 4 de listes référencent un commun, dont une liste envoyée ; après : aucune
        $this->assertSame([60, 12, 4], [$before->watchedRowCounts[AdoptionSnapshot::RECIPE_ROW], $before->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_ROW], $before->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_INGREDIENT]]);
        $this->assertSame([20, 4, 1], [$before->recipesToRepoint(), $before->shoppingListsToRepoint(), $before->sentShoppingListsToRepoint()]);
        $this->assertSame(0, $after->watchedRows());
        $this->assertSame(0, $after->sentShoppingListsToRepoint());
        $this->assertSame(array_map(static fn($r): int => $r->version + 1, $before->recipes), array_map(static fn($r): int => $r->version, $after->recipes));
        // Un autre repointage (vers des slugs surveillés précis) : seuls ceux-là comptent
        $this->assertSame(0, $this->repository->snapshot($user, ['slug-inconnu'])->watchedRows());
        $this->assertSame(0, $this->repository->snapshot($user, [])->watchedRows());
        $this->assertGreaterThan(0, $this->repository->snapshot($user, ['beurre' . $user])->watchedRows());
    }

    public function testTheSnapshotIsSensitiveToAnyChangeOfQuantityNameUnitOrSituation(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->singleId;
        $base = $this->repository->snapshot($user, [])->contentFingerprint();
        $changes = [
            'une quantité' => "UPDATE shopping_list_row SET quantity = quantity + 0.001 WHERE shopping_list IN (SELECT id FROM shopping_list WHERE owner = '$user')",
            'une unité' => "UPDATE shopping_list_row SET unit = 'kilo' WHERE shopping_list IN (SELECT id FROM shopping_list WHERE owner = '$user')",
            'le statut' => "UPDATE shopping_list SET status = 'PAUSED' WHERE owner = '$user'",
            'le rayon d\'un ingrédient' => "UPDATE ingredient SET department = 'rayon-z' WHERE slug = 'beurre'",
            'le nom d\'un ingrédient' => "UPDATE ingredient SET name = 'Beurre doux' WHERE slug = 'beurre'",
        ];

        foreach ($changes as $label => $sql) {
            $dataset->connection->beginTransaction();
            $dataset->connection->executeStatement($sql);
            $this->assertNotSame($base, $this->repository->snapshot($user, [])->contentFingerprint(), "l'empreinte doit voir $label");
            $dataset->connection->rollBack();
            $this->assertSame($base, $this->repository->snapshot($user, [])->contentFingerprint(), "l'empreinte revient quand $label revient");
        }
    }

    public function testNonConformingCopiesAreFoundOnEveryColumnAndOnConversions(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->singleId;
        $this->adoptEverything($user);
        $adoptions = $this->repository->adoptions($user);

        $this->assertSame([], $this->repository->nonConformingCopies($user, $adoptions));
        $this->assertCount(12, $adoptions);

        foreach ([
            'le nom' => "UPDATE ingredient SET name = 'Autre' WHERE slug = ?",
            'l\'image' => "UPDATE ingredient SET image = 'x.png' WHERE slug = ?",
            'le rayon' => "UPDATE ingredient SET department = 'rayon-z' WHERE slug = ?",
            'l\'unité d\'achat' => "UPDATE ingredient SET default_purchase_unit = 'litre' WHERE slug = ?",
            'les unités compatibles' => "UPDATE ingredient SET compatible_units = '[\"x\"]' WHERE slug = ?",
            'le créateur' => "UPDATE ingredient SET creator = 'quelqu-un' WHERE slug = ?",
            'la provenance' => "UPDATE ingredient SET adopted_from = 'autre-commun' WHERE slug = ?",
        ] as $label => $sql) {
            $dataset->connection->beginTransaction();
            $dataset->connection->executeStatement($sql, ['beurre' . $user]);
            $this->assertSame(['beurre'], $this->repository->nonConformingCopies($user, $adoptions), "doit voir $label");
            $dataset->connection->rollBack();
        }
        // Un coefficient de conversion propre changé, ou une conversion de plus
        $dataset->connection->executeStatement("UPDATE conversion SET coefficient = 126 WHERE ingredient = ? AND start_unit = 'unite'", ['beurre' . $user]);
        $this->assertSame(['beurre'], $this->repository->nonConformingCopies($user, $adoptions));
        $dataset->connection->executeStatement("UPDATE conversion SET coefficient = 125 WHERE ingredient = ? AND start_unit = 'unite'", ['beurre' . $user]);
        $dataset->connection->executeStatement("DELETE FROM conversion WHERE ingredient = ? AND start_unit = 'unite'", ['beurre' . $user]);
        $this->assertSame(['beurre'], $this->repository->nonConformingCopies($user, $adoptions));
    }

    // --- Aides

    private function adoptEverything(string $userId, string $source = 'migration-essai'): void
    {
        $toAdopt = [];
        $taken = array_flip($this->repository->takenSlugs($userId));
        foreach ($this->dataset->commons as $commonSlug) {
            $name = $this->dataset->commonNames[$commonSlug];
            $slug = StringTool::slugify($name . $userId);
            for ($attempt = 2; isset($taken[$slug]); ++$attempt) {
                $slug = StringTool::slugify($name . '-' . $attempt . $userId);
            }
            $taken[$slug] = true;
            $toAdopt[$commonSlug] = $slug;
        }
        $written = $this->repository->writeAliases($userId, $toAdopt, $source, $this->now);
        $this->repository->copyCatalogIngredients($userId, $this->dataset->adminId, $written);
        $this->repository->copyConversions(array_map(
            static fn(array $c): array => ['sourceId' => $c['id'], 'newId' => UuidGenerator::new(), 'copySlug' => $written[$c['commonSlug']]],
            $this->repository->ownConversionsOf(array_keys($written)),
        ));
    }

    private function insertAlias(string $userId, string $aliasSlug, string $ingredientSlug, string $source = 'ancien'): void
    {
        $this->dataset->insert('ingredient_alias', ['user_id' => $userId, 'alias_slug' => $aliasSlug, 'ingredient_slug' => $ingredientSlug, 'kind' => 'adoption', 'source' => $source, 'created_at' => '2026-10-01 10:00:00']);
    }

}

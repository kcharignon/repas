<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Shared\Application\Interface\CommandBusInterface;
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
        $this->now = new DateTimeImmutable('2026-10-21 03:00:00');
    }

    public function testTheScopeIsNonAdministratorsWithARecipeOrAPersonalIngredient(): void
    {
        $dataset = $this->dataset;

        $this->assertTrue($this->repository->isConcerned($dataset->bigId), 'recettes et ingrédients personnels');
        $this->assertTrue($this->repository->isConcerned($dataset->copierId), 'seulement des recettes copiées');
        $this->assertTrue($this->repository->isConcerned($dataset->singleId), 'seulement un ingrédient personnel');
        $this->assertTrue($this->repository->isConcerned($dataset->disabledId), 'le statut du compte ne change pas le périmètre');
        $this->assertFalse($this->repository->isConcerned($dataset->emptyId), 'une liste et un choix du drive ne suffisent pas');
        $this->assertFalse($this->repository->isConcerned($dataset->adminId), 'un administrateur garde les communs');
        $this->assertFalse($this->repository->isConcerned('00000000-0000-4000-8000-00000000dead'), 'un compte inconnu');
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

    public function testTheCommonsToAdoptAreThoseWithoutAValidAdoptionAndAnIdenticalExistingCopyIsOffered(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->bigId;

        // Given : un alias valide (beurre), un alias ORPHELIN (farine : la copie n'existe pas), une copie conforme sans alias (lait)
        $dataset->insert('ingredient', ['slug' => 'beurre' . $user, 'name' => 'Beurre', 'image' => 'images/beurre.png', 'slugged_name' => 'beurre', 'department' => 'rayon-a', 'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => $user, 'compatible_units' => '["gramme","kilo"]', 'adopted_from' => 'beurre']);
        $this->insertAlias($user, 'beurre', 'beurre' . $user);
        $this->insertAlias($user, 'farine', 'farine' . $user);
        $milk = $this->dataset->connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = 'lait'");
        $dataset->insert('ingredient', [...$milk, 'slug' => 'lait' . $user, 'creator' => $user, 'adopted_from' => 'lait']);

        // When
        $commons = $this->repository->commonsToAdopt($user);

        // Then : tous sauf le beurre ; la farine (alias orphelin) reste à adopter ; le lait désigne la copie conforme
        $slugs = array_map(static fn($common): string => $common->slug, $commons);
        $this->assertSame(11, count($slugs));
        $this->assertNotContains('beurre', $slugs);
        $this->assertContains('farine', $slugs);
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        $byCommon = array_column(array_map(static fn($c): array => [$c->slug, $c->existingCopySlug], $commons), 1, 0);
        $this->assertSame('lait' . $user, $byCommon['lait']);
        $this->assertNull($byCommon['farine']);

        // When : la copie du lait a été modifiée
        $dataset->connection->executeStatement("UPDATE ingredient SET department = 'rayon-b' WHERE slug = ?", ['lait' . $user]);

        // Then : elle n'est plus proposée
        $this->assertNull(array_column(array_map(static fn($c): array => [$c->slug, $c->existingCopySlug], $this->repository->commonsToAdopt($user)), 1, 0)['lait']);
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

    public function testTheBulkCopyWritesTheSameLinesAsTheIndividualAdoptionOfACommon(): void
    {
        // Given : le beurre (deux conversions propres), construit par le domaine (le cas d'usage d'ING-001 charge unités et rayon), adopté pour A
        // par ce cas d'usage, pour B en masse
        $dataset = $this->dataset;
        $dataset->connection->executeStatement("DELETE FROM conversion WHERE ingredient = 'beurre'");
        $persister = new DataPersister(static::getContainer());
        $persister->saveIngredient(new IngredientBuilder()->isButter()->build());
        $persister->saveConversion(new ConversionBuilder()->isBlockToGrammeForButter()->build());
        $persister->saveConversion(new ConversionBuilder()->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(15)->withIngredient(new IngredientBuilder()->isButter())->build());
        $a = $dataset->singleId;
        $b = $dataset->copierId;
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new AdoptCommonIngredientCommand($a, 'beurre', 'migration-essai'));
        $copySlug = 'beurre' . $b;
        $written = $this->repository->writeAliases($b, ['beurre' => $copySlug], 'migration-essai', $this->now);
        $this->repository->copyIngredients($b, $written);
        $conversions = $this->repository->ownConversionsOf(['beurre']);
        $this->repository->copyConversions(array_map(static fn(array $c): array => ['sourceId' => $c['id'], 'newId' => UuidGenerator::new(), 'copySlug' => $copySlug], $conversions));

        // Then : mêmes colonnes (au slug et au créateur près), mêmes conversions (nouveaux identifiants), même alias
        $columns = 'name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, compatible_units::text, adopted_from';
        $this->assertSame(
            $dataset->connection->fetchNumeric("SELECT $columns FROM ingredient WHERE slug = ?", ['beurre' . $a]),
            $dataset->connection->fetchNumeric("SELECT $columns FROM ingredient WHERE slug = ?", [$copySlug]),
        );
        $this->assertSame(
            $dataset->connection->fetchAllNumeric('SELECT start_unit, end_unit, coefficient FROM conversion WHERE ingredient = ? ORDER BY start_unit, end_unit', ['beurre' . $a]),
            $dataset->connection->fetchAllNumeric('SELECT start_unit, end_unit, coefficient FROM conversion WHERE ingredient = ? ORDER BY start_unit, end_unit', [$copySlug]),
        );
        $this->assertCount(2, $conversions);
        $this->assertSame(
            $dataset->connection->fetchNumeric("SELECT kind, source FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'beurre'", [$a]),
            $dataset->connection->fetchNumeric("SELECT kind, source FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'beurre'", [$b]),
        );
        $this->assertSame(
            [],
            array_intersect(
                $dataset->connection->fetchFirstColumn('SELECT id FROM conversion WHERE ingredient = ?', ['beurre' . $a]),
                $dataset->connection->fetchFirstColumn('SELECT id FROM conversion WHERE ingredient = ?', [$copySlug]),
            ),
            'nouveaux identifiants de conversions',
        );
        $this->assertSame(1, (int) $dataset->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'), 'la conversion commune n\'est pas copiée');
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
        $before = $this->repository->snapshot($user);
        $this->adoptEverything($user);
        $this->repository->repointToCopies($user);
        $after = $this->repository->snapshot($user);

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
        $base = $this->repository->snapshot($user)->contentFingerprint();
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
            $this->assertNotSame($base, $this->repository->snapshot($user)->contentFingerprint(), "l'empreinte doit voir $label");
            $dataset->connection->rollBack();
            $this->assertSame($base, $this->repository->snapshot($user)->contentFingerprint(), "l'empreinte revient quand $label revient");
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

    public function testTheRunsAdoptionsAreListedWithTheSignOfAModifiedCopyAndRepointedBackWithoutDeletingAnyCopy(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->copierId;
        $this->adoptEverything($user, 'lancement-a');
        $this->adoptEverything($dataset->singleId, 'lancement-b');
        $this->repository->repointToCopies($user);
        $dataset->connection->executeStatement("UPDATE ingredient SET name = 'Farine de blé' WHERE slug = ?", ['farine' . $user]);
        $copies = (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$user]);

        // When
        $adoptions = $this->repository->adoptionsOfRun($user, 'lancement-a');

        // Then : 12 adoptions du lancement, la farine modifiée seule est signalée ; un autre lancement n'en voit aucune
        $this->assertCount(12, $adoptions);
        $this->assertSame(['farine'], array_values(array_map(static fn($a): string => $a->commonSlug, array_filter($adoptions, static fn($a): bool => $a->modified))));
        $this->assertSame([], $this->repository->adoptionsOfRun($user, 'lancement-c'));

        // When : retour arrière de celles qui ne sont pas modifiées
        $undoable = array_filter($adoptions, static fn($a): bool => !$a->modified);
        $map = array_column(array_map(static fn($a): array => [$a->copySlug, $a->commonSlug], $undoable), 1, 0);
        $repointed = $this->repository->repointToCommons($user, $map);
        $deleted = $this->repository->deleteAdoptionAliases($user, 'lancement-a', array_values($map));

        // Then : les lignes sont revenues sur les communs sauf celles de la copie modifiée, 11 alias effacés, aucune copie supprimée
        $this->assertGreaterThan(0, $repointed->recipeRows);
        $this->assertSame(11, $deleted);
        $this->assertSame($copies, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$user]));
        $this->assertSame(['farine'], $dataset->connection->fetchFirstColumn('SELECT alias_slug FROM ingredient_alias WHERE user_id = ?', [$user]));
        $this->assertSame(12, (int) $dataset->connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE user_id = ? AND source = 'lancement-b'", [$dataset->singleId]), 'un autre compte n\'est pas touché');
        $this->assertSame(0, $this->repository->deleteAdoptionAliases($user, 'lancement-a', []));
        $this->assertTrue($this->repository->repointToCommons($user, [])->isEmpty());
    }

    // --- Aides

    private function adoptEverything(string $userId, string $source = 'migration-essai'): void
    {
        $toAdopt = [];
        $taken = array_flip($this->repository->takenSlugs($userId));
        foreach ($this->repository->commonsToAdopt($userId) as $common) {
            $slug = StringTool::slugify($common->name . $userId);
            for ($attempt = 2; isset($taken[$slug]); ++$attempt) {
                $slug = StringTool::slugify($common->name . '-' . $attempt . $userId);
            }
            $taken[$slug] = true;
            $toAdopt[$common->slug] = $slug;
        }
        $written = $this->repository->writeAliases($userId, $toAdopt, $source, $this->now);
        $this->repository->copyIngredients($userId, $written);
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

<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Interface\IngredientCatalogHandoverRepository;
use Repas\Repas\Domain\Model\CatalogHandoverState;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * Le SQL de la passation du catalogue (ING-009) sur la VRAIE base : la lecture de l'état dont dépendent les préconditions (propriétaire,
 * ingrédients sans créateur, lignes des autres comptes, comptes actifs sans amorçage, orphelins, choix du drive compris), l'écriture du
 * classement et du créateur (seulement les ingrédients sans créateur, seulement ce qui change) et l'empreinte des ingrédients.
 */
class IngredientCatalogHandoverRepositoryTest extends DatabaseTestCase
{
    private IngredientCatalogHandoverRepository $repository;
    private HandoverDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(IngredientCatalogHandoverRepository::class);
        $this->dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()), migratedAccounts: 2);
    }

    public function testTheStateOfTheProductionLikeBaseBeforeTheHandOver(): void
    {
        // When
        $state = $this->state();

        // Then : le propriétaire, un seul administrateur, les 261 anciens communs triés (ordre binaire) avec un marqueur faux (classement pas posé)
        $this->assertSame([$this->dataset->ownerId, 'ACTIVE', true, 1], [$state->ownerId, $state->ownerStatus, $state->ownerIsAdministrator, $state->administrators]);
        $sorted = $this->dataset->slugs;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $state->slugsWithoutCreator);
        $this->assertSame(array_fill_keys($sorted, false), $state->markersWithoutCreator);
        $this->assertSame([0, 0], [$state->withoutCreatorWithOrigin, $state->withoutCreatorAccountFormat]);
        $this->assertSame((int) $this->dataset->connection->fetchOne('SELECT count(*) FROM conversion v JOIN ingredient c ON c.slug = v.ingredient WHERE c.creator IS NULL'), $state->ownConversionsWithoutCreator);
        $this->assertSame([], $state->fileSlugsAtOwner);
        // …le compte vide est le seul à avoir des lignes sur un ancien commun (listes, ingrédients de liste, choix du drive ; aucune recette)
        $this->assertSame([$this->dataset->emptyId], array_keys($state->foreignLinesByAccount));
        $this->assertEquals(['choix du drive' => 2, 'ingrédients de liste' => 1, 'lignes de liste' => 5], $state->foreignLinesByAccount[$this->dataset->emptyId]);
        $this->assertEquals(['choix du drive' => 2, 'ingrédients de liste' => 1, 'lignes de liste' => 5], $state->foreignLines());
        // …le propriétaire n'a de ligne que sur des communs ou sur lui-même, il n'y a aucun orphelin
        $this->assertSame([], $state->ownerLinesOnOthers);
        $this->assertSame([], $state->orphans);
        // …le compte vide est actif, non amorcé, sans ingrédient ; les comptes migrés sont amorcés ; les comptes en attente et désactivé ne comptent pas
        $this->assertSame([$this->dataset->emptyId], $state->activeNotSeeded);
        $this->assertSame([$this->dataset->emptyId], $state->activeWithoutIngredient);
    }

    public function testTheStateSeesEachKindOfAnomaly(): void
    {
        // Given : un ancien commun avec une origine, un autre au format d'un slug de compte, un orphelin de chaque table, un choix du drive orphelin,
        // une ligne du propriétaire sur l'ingrédient personnel d'un migré, un compte actif amorcé sans ingrédient, un second administrateur
        $connection = $this->dataset->connection;
        $connection->executeStatement("UPDATE ingredient SET adopted_from = 'quelque-chose' WHERE slug = 'farine'");
        $this->dataset->adoption->insert('ingredient', ['slug' => 'sel-de-table' . $this->dataset->ownerId, 'name' => 'Sel de table', 'image' => '', 'slugged_name' => 'sel-de-table', 'department' => 'sauce', 'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => null, 'compatible_units' => '[]', 'adopted_from' => null]);
        $recipe = (string) $connection->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$this->dataset->ownerId]);
        $list = (string) $connection->fetchOne('SELECT id FROM shopping_list WHERE owner = ? LIMIT 1', [$this->dataset->ownerId]);
        $connection->insert('recipe_row', ['id' => UuidGenerator::new(), 'ingredient' => 'fantome-a', 'quantity' => 1.0, 'unit' => 'gramme', 'recipe' => $recipe, 'position' => 90]);
        $connection->insert('shopping_list_row', ['id' => UuidGenerator::new(), 'shopping_list' => $list, 'ingredient' => 'fantome-b', 'quantity' => 1.0, 'unit' => 'gramme', 'checked' => 'false']);
        $connection->insert('shopping_list_ingredient', ['id' => UuidGenerator::new(), 'shopping_list' => $list, 'ingredient' => 'fantome-c', 'unit' => 'unite', 'quantity' => 1.0]);
        $this->dataset->adoption->choice($this->dataset->ownerId, 'fantome-d', '1234', 'leclerc', '999999');
        $connection->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => 'unite', 'end_unit' => 'gramme', 'coefficient' => 2.0, 'ingredient' => 'fantome-e']);
        $personal = $this->dataset->personalSlugs[$this->dataset->migratedIds[0]][0];
        $connection->insert('recipe_row', ['id' => UuidGenerator::new(), 'ingredient' => $personal, 'quantity' => 1.0, 'unit' => 'gramme', 'recipe' => $recipe, 'position' => 91]);
        $this->dataset->adoption->choice($this->dataset->ownerId, $personal, '4321', 'leclerc', '999999');
        $second = new UserBuilder()->isAdmin()->build();
        new DataPersister(static::getContainer())->saveUser($second);

        // When
        $state = $this->state();

        // Then
        $this->assertSame(1, $state->withoutCreatorWithOrigin);
        $this->assertSame(1, $state->withoutCreatorAccountFormat);
        $this->assertEquals(['choix du drive' => 1, 'conversions' => 1, 'ingrédients de liste' => 1, 'lignes de liste' => 1, 'recettes' => 1], $state->orphans);
        $this->assertEquals(['choix du drive' => 1, 'recettes' => 1], $state->ownerLinesOnOthers);
        $this->assertSame(2, $state->administrators);
        $this->assertSame($this->dataset->ownerId, $state->ownerId, 'le propriétaire reste le compte du port, pas « tout administrateur »');
    }

    public function testTheStateAfterTheHandOverFindsTheOldCommonsAtTheOwnerWithTheirMarker(): void
    {
        // Given : le classement posé et la passation faite par le dépôt
        $this->repository->classify($this->dataset->classification);
        $slugs = $this->repository->slugsWithoutCreator();
        $this->assertSame(261, $this->repository->assignToOwner($this->dataset->ownerId, $slugs));

        // When
        $state = $this->state();

        // Then : plus aucun sans créateur ; les 261 sont chez le propriétaire avec le marqueur du fichier
        $this->assertSame([], $state->slugsWithoutCreator);
        $this->assertEquals($this->dataset->classification, $state->fileSlugsAtOwner);
        $this->assertCount(261, $state->fileSlugsAtOwner);
        $this->assertSame(151, count(array_filter($state->fileSlugsAtOwner)));
        $this->assertTrue($state->isHandedOver($this->dataset->classification));
        // …et l'ancien commun qui aurait une origine n'est pas compté comme « chez le propriétaire »
        $this->dataset->connection->executeStatement("UPDATE ingredient SET adopted_from = 'quelque-chose' WHERE slug = 'farine'");
        $this->assertFalse($this->state()->isHandedOver($this->dataset->classification));
    }

    public function testClassifyWritesTheMarkerOfTheIngredientsWithoutCreatorOnlyAndOnlyWhatChanges(): void
    {
        // Given : un ingrédient du propriétaire (il a un créateur) dont le slug est aussi dans le classement qu'on passe, un ancien commun déjà de base
        $connection = $this->dataset->connection;
        $personal = $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Ingredient perso du proprietaire', 'sauce');
        $connection->executeStatement("UPDATE ingredient SET is_base = TRUE WHERE slug = 'farine'");
        $classification = $this->dataset->classification + [$personal => true];
        $copyBefore = $connection->fetchAssociative('SELECT * FROM ingredient WHERE adopted_from IS NOT NULL ORDER BY slug LIMIT 1');

        // When
        $changed = $this->repository->classify($classification);

        // Then : 151 de base dont « farine », déjà de base (donc 150 écrits) ; le personnel du propriétaire et les copies n'ont pas bougé
        $this->assertSame(150, $changed);
        $this->assertSame(151, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL AND is_base'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT is_base FROM ingredient WHERE slug = ?', [$personal]));
        $this->assertSame($copyBefore, $connection->fetchAssociative('SELECT * FROM ingredient WHERE adopted_from IS NOT NULL ORDER BY slug LIMIT 1'));
        // Le rejouer n'écrit rien
        $this->assertSame(0, $this->repository->classify($classification));
        $this->assertSame(0, $this->repository->classify([]));
        // Et il corrige un marqueur faux dans l'autre sens
        $connection->executeStatement("UPDATE ingredient SET is_base = TRUE WHERE slug = 'agaragar'");
        $this->assertSame(1, $this->repository->classify($classification));
        $this->assertFalse((bool) $connection->fetchOne("SELECT is_base FROM ingredient WHERE slug = 'agaragar'"));
    }

    public function testAssignToOwnerChangesTheCreatorOfTheIngredientsWithoutCreatorOnly(): void
    {
        // Given : la liste contient aussi un ingrédient déjà à un autre compte (jamais touché) et un slug qui n'existe pas
        $connection = $this->dataset->connection;
        $other = $this->dataset->personalSlugs[$this->dataset->migratedIds[0]][0];
        $slugs = [...$this->repository->slugsWithoutCreator(), $other, 'inexistant'];
        $before = $connection->fetchAssociative('SELECT * FROM ingredient WHERE slug = ?', [$other]);

        // When
        $assigned = $this->repository->assignToOwner($this->dataset->ownerId, $slugs);

        // Then : 261 passés, rien d'autre ; l'autre compte garde son ingrédient ; un rejeu ne change rien
        $this->assertSame(261, $assigned);
        $this->assertSame($before, $connection->fetchAssociative('SELECT * FROM ingredient WHERE slug = ?', [$other]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
        $this->assertSame(0, $this->repository->assignToOwner($this->dataset->ownerId, $slugs));
        $this->assertSame(0, $this->repository->assignToOwner($this->dataset->ownerId, []));
    }

    public function testTheFingerprintIgnoresTheCreatorAndTheMarkerButSeesAnyOtherChange(): void
    {
        // Given
        $connection = $this->dataset->connection;
        $slugs = $this->repository->slugsWithoutCreator();
        $reversed = array_reverse($slugs);
        $fingerprint = $this->repository->fingerprint($slugs);

        // Then : stable, indépendante de l'ordre des slugs demandés, du créateur et du marqueur
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint);
        $this->assertSame($fingerprint, $this->repository->fingerprint($reversed));
        $this->repository->classify($this->dataset->classification);
        $this->repository->assignToOwner($this->dataset->ownerId, $slugs);
        $this->assertSame($fingerprint, $this->repository->fingerprint($slugs));
        $this->assertSame('', $this->repository->fingerprint([]));

        // …et sensible à tout le reste : nom, image, rayon, unités, origine, une conversion propre ajoutée, modifiée ou retirée
        foreach ([
            "UPDATE ingredient SET name = name || ' x' WHERE slug = 'farine'",
            "UPDATE ingredient SET image = 'images/autre.png' WHERE slug = 'farine'",
            "UPDATE ingredient SET department = 'sauce' WHERE slug = 'farine' AND department <> 'sauce'",
            "UPDATE ingredient SET default_purchase_unit = 'unite' WHERE slug = 'farine'",
            "UPDATE ingredient SET compatible_units = '[\"gramme\"]' WHERE slug = 'farine'",
            "UPDATE ingredient SET adopted_from = 'x' WHERE slug = 'farine'",
            "INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient) VALUES ('c-nouvelle', 'unite', 'gramme', 3, 'farine')",
        ] as $change) {
            $connection->executeStatement('SAVEPOINT essai');
            $connection->executeStatement($change);
            $this->assertNotSame($fingerprint, $this->repository->fingerprint($slugs), $change);
            $connection->executeStatement('ROLLBACK TO SAVEPOINT essai');
        }
        $conversion = (string) $connection->fetchOne('SELECT id FROM conversion WHERE ingredient IN (SELECT slug FROM ingredient WHERE adopted_from IS NULL AND creator = ?) ORDER BY id LIMIT 1', [$this->dataset->ownerId]);
        $ingredient = (string) $connection->fetchOne('SELECT ingredient FROM conversion WHERE id = ?', [$conversion]);
        $withConversions = $this->repository->fingerprint([$ingredient]);
        $connection->executeStatement('UPDATE conversion SET coefficient = coefficient + 1 WHERE id = ?', [$conversion]);
        $this->assertNotSame($withConversions, $this->repository->fingerprint([$ingredient]));
        $connection->executeStatement('DELETE FROM conversion WHERE id = ?', [$conversion]);
        $this->assertNotSame($withConversions, $this->repository->fingerprint([$ingredient]));
        $this->assertNotSame($fingerprint, $this->repository->fingerprint($slugs), 'la conversion retirée change aussi l\'empreinte du lot');
    }

    public function testTheHealthDefinitionsAreTheOnesOfTheSeedingHealthQuery(): void
    {
        // Given : un état varié (un compte actif amorcé sans copie, un compte actif neuf, une ligne d'un compte en attente sur un ancien commun)
        $connection = $this->dataset->connection;
        $this->dataset->adoption->recipe($this->dataset->pendingId, 'Recette en attente', ['farine']);
        $noCopies = new UserBuilder()->build();
        $fresh = new UserBuilder()->build();
        new DataPersister(static::getContainer())->saveUser($noCopies);
        new DataPersister(static::getContainer())->saveUser($fresh);
        $connection->insert('ingredient_alias', ['user_id' => $noCopies->getId(), 'alias_slug' => 'farine', 'ingredient_slug' => 'farine' . $noCopies->getId(), 'kind' => 'adoption', 'source' => 'essai', 'created_at' => '2026-10-20 10:00:00']);
        $this->entityManager->clear();

        // When : l'état de la passation et la requête de santé de l'amorçage (ING-008), qui doivent dire la même chose
        $state = $this->state();
        $health = static::getContainer()->get(IngredientSeedingReportPostgreSQLLoader::class)->report([], [], false)->health;

        // Then
        $this->assertSame($health->activeNotSeeded, count($state->activeNotSeeded));
        $this->assertSame($health->activeWithoutIngredient, count($state->activeWithoutIngredient));
        $this->assertContains($fresh->getId(), $state->activeNotSeeded);
        $this->assertNotContains($noCopies->getId(), $state->activeNotSeeded, 'il a un alias d\'adoption');
        $this->assertContains($noCopies->getId(), $state->activeWithoutIngredient);
        // Les lignes des autres comptes sur un ingrédient sans créateur : le compte vide ET le compte en attente (quel que soit le statut)
        $this->assertEqualsCanonicalizing([$this->dataset->emptyId, $this->dataset->pendingId], array_keys($state->foreignLinesByAccount));
        $this->assertSame($health->foreignLines['recettes'] ?? 0, $state->foreignLines()['recettes'] ?? 0);
        $this->assertSame($health->foreignLines['choix du drive'] ?? 0, $state->foreignLines()['choix du drive'] ?? 0);
        // Et « amorcé » est la définition du port d'amorçage d'ING-008
        $bulk = static::getContainer()->get(IngredientAdoptionBulkRepository::class);
        foreach ([$this->dataset->emptyId, $this->dataset->migratedIds[0], $noCopies->getId(), $fresh->getId()] as $id) {
            $this->assertSame($bulk->isSeeded($id), !in_array($id, $state->activeNotSeeded, true), $id);
        }
    }

    public function testReadingTheStateWritesNothingAndTakesNoLock(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->state();
        $this->repository->slugsWithoutCreator();
        $this->repository->fingerprint($this->repository->slugsWithoutCreator());

        // Then
        $this->assertGreaterThan(10, $counter->count());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|pg_advisory/i', $sql) === 1)));
    }

    public function testTheLockIsTakenForTheTransactionAndCanBeTakenTwice(): void
    {
        $this->repository->lock();
        $this->repository->lock();

        $this->assertSame(1, (int) $this->dataset->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    private function state(): CatalogHandoverState
    {
        $this->entityManager->clear();

        return $this->repository->state(static::getContainer()->get(IngredientCatalogOwner::class)->ownerId(), $this->dataset->classification);
    }
}

<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Loader\CatalogHandoverReportLoader;
use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;
use Repas\Repas\Domain\Model\HandoverPrecondition;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * Le rapport « à blanc » de la passation du catalogue (ING-009) sur la VRAIE base, dans chaque état de la passation : avant le classement, après
 * le classement et l'amorçage, une fois passée ; un classement qui ne correspond pas ; sans propriétaire. Il est en lecture seule, ne montre
 * que des nombres et des références courtes, et lit l'amorçage « comme si le classement était posé ».
 */
class CatalogHandoverReportPostgreSQLLoaderTest extends DatabaseTestCase
{
    private CatalogHandoverReportLoader $loader;
    private HandoverDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature($this->name() !== 'testBeforeTheClassificationEverythingIsReadAsIfTheFileWasPosedAndTheSwitchIsOff'));
        $this->loader = static::getContainer()->get(CatalogHandoverReportLoader::class);
        $this->dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()), migratedAccounts: 2);
    }

    public function testBeforeTheClassificationEverythingIsReadAsIfTheFileWasPosedAndTheSwitchIsOff(): void
    {
        // When
        $report = $this->loader->report();

        // Then : le propriétaire, désigné par une référence courte (les 8 premiers caractères du SHA-256 de son identifiant)
        $this->assertTrue($report->ownerExists);
        $this->assertSame(substr(hash('sha256', $this->dataset->ownerId), 0, 8), $report->ownerReference);
        $this->assertSame(['ACTIVE', true, 1], [$report->ownerStatus, $report->ownerIsAdministrator, $report->administrators]);
        // …le fichier et les anciens communs : 261, 151 de base, les sommes de contrôle du document
        $this->assertSame([261, 151, 'a43e401aaf59b6e65bd1f433f7347c7b', '3776a0ad5d1fbc427f9431656c14b7e2'], [$report->fileEntries, $report->fileBase, $report->fileChecksum, $report->fileBaseChecksum]);
        $this->assertSame([261, false, 261, 'a43e401aaf59b6e65bd1f433f7347c7b', 0, md5('')], [$report->withoutCreator, $report->catalogAtOwner, $report->catalogCount, $report->catalogChecksum, $report->catalogMarked, $report->catalogMarkedChecksum]);
        $this->assertSame([0, 0, 151, 0, 0], [$report->unknown, $report->missing, $report->markersToWrite, $report->withOrigin, $report->accountFormat]);
        $this->assertSame([0, 0, false, false], [$report->atOwner, $report->atOwnerMarkersDiffering, $report->handedOver, $report->seedingEnabled]);
        $this->assertTrue($report->matchesTheFile());
        $this->assertFalse($report->isClassified());
        $this->assertSame([], $report->blockingAnomalies);
        // …l'amorçage est lu comme si le classement était posé : le compte vide recevrait 156 ingrédients (151 « de base » et cinq que ses lignes référencent)
        $this->assertSame([261, 151], [$report->seeding->catalogIngredients, $report->seeding->baseIngredients]);
        $this->assertCount(1, $report->seeding->selected());
        $this->assertSame([156, 151, 5, 1], [$report->seeding->selected()[0]->toSeed, $report->seeding->selected()[0]->toSeedBase, $report->seeding->selected()[0]->toSeedReferenced, $report->seeding->sentShoppingListsToRepoint()]);
        $this->assertSame([], $report->seeding->selected()[0]->blockingAnomalies);
        // …alors que la lecture RÉELLE de l'amorçage (marqueurs de la base) dit qu'il n'y a aucun ingrédient « de base » et refuse
        $real = static::getContainer()->get(IngredientSeedingReportLoader::class)->report([], [], false);
        $this->assertSame(0, $real->baseIngredients);
        $this->assertNotSame([], $real->selected()[0]->blockingAnomalies);
        // …les préconditions : seules P2 et P3 sont levées par l'amorçage, P4 manque et ne l'est pas
        $byCode = $this->byCode($report);
        $this->assertSame(['P1' => true, 'P2' => false, 'P3' => false, 'P4' => false, 'P5' => true, 'P6' => true, 'P7' => true], array_map(static fn(HandoverPrecondition $p): bool => $p->met, $byCode));
        $this->assertSame(['P1' => true, 'P2' => true, 'P3' => true, 'P4' => false, 'P5' => true, 'P6' => true, 'P7' => true], array_map(static fn(HandoverPrecondition $p): bool => $p->metAfterSeeding, $byCode));
        $this->assertFalse($report->isReadyToHandOver());
        $this->assertFalse($report->isReadyToHandOverAfterSeeding());
        $this->assertSame(['P4'], array_map(static fn(HandoverPrecondition $p): string => $p->code, $report->unmetAfterSeeding()));
        $this->assertFalse($report->isConform());
    }

    public function testWithTheSwitchOnTheFullApplyIsReadyAfterTheSeeding(): void
    {
        // When
        $report = $this->loader->report();

        // Then
        $this->assertTrue($report->seedingEnabled);
        $this->assertFalse($report->isReadyToHandOver(), 'P2 et P3 manquent aujourd\'hui');
        $this->assertTrue($report->isReadyToHandOverAfterSeeding());
        $this->assertSame(['P2', 'P3'], array_map(static fn(HandoverPrecondition $p): string => $p->code, $report->unmet()));
    }

    public function testAfterTheClassificationAndTheSeedingEveryPreconditionIsMetAndTheMarkersAreTheFiles(): void
    {
        // Given : phases 1 et 2 par les cas d'usage
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new ClassifyBaseIngredientsCommand());
        $bus->dispatch(new SeedUserIngredientsCommand($this->dataset->emptyId, 'rattrapage-essai'));
        $this->entityManager->clear();

        // When
        $report = $this->loader->report();

        // Then
        $this->assertSame([0, 151, '3776a0ad5d1fbc427f9431656c14b7e2'], [$report->markersToWrite, $report->catalogMarked, $report->catalogMarkedChecksum]);
        $this->assertTrue($report->isClassified());
        $this->assertTrue($report->seeding->isNothingToDo());
        $this->assertTrue($report->isReadyToHandOver());
        $this->assertFalse($report->handedOver);
        $this->assertFalse($report->isConform(), 'la passation n\'est pas faite');
        $this->assertSame([], $report->unmet());
    }

    public function testOnceHandedOverTheReportSaysSoAndIsConform(): void
    {
        // Given
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new ClassifyBaseIngredientsCommand());
        $bus->dispatch(new SeedUserIngredientsCommand($this->dataset->emptyId, 'rattrapage-essai'));
        $bus->dispatch(new HandOverCatalogCommand());
        static::getContainer()->get(IngredientCatalogOwner::class)->reset();
        $this->entityManager->clear();

        // When
        $report = $this->loader->report();

        // Then : plus rien sans créateur, les 261 sont chez le propriétaire (mêmes sommes de contrôle), plus de préconditions à juger
        $this->assertSame([0, true, 261, 'a43e401aaf59b6e65bd1f433f7347c7b', 151, '3776a0ad5d1fbc427f9431656c14b7e2'], [$report->withoutCreator, $report->catalogAtOwner, $report->catalogCount, $report->catalogChecksum, $report->catalogMarked, $report->catalogMarkedChecksum]);
        $this->assertSame([261, 0], [$report->atOwner, $report->atOwnerMarkersDiffering]);
        $this->assertTrue($report->handedOver);
        $this->assertSame([], $report->preconditions);
        $this->assertTrue($report->isConform());
        $this->assertTrue($report->isNothingToDo());
        // …et un marqueur que le propriétaire change ensuite n'est qu'une information
        $this->dataset->connection->executeStatement("UPDATE ingredient SET is_base = NOT is_base WHERE slug = 'farine'");
        $changed = $this->loader->report();
        $this->assertSame(1, $changed->atOwnerMarkersDiffering);
        $this->assertTrue($changed->isConform());
    }

    public function testAnOldCommonOutsideTheFileAndOneMissingAreBlockingAnomaliesNamedWithoutAnAccountSlug(): void
    {
        // Given : un commun inconnu du fichier, un autre dont le slug a la forme d'un slug de compte, et « farine » qui manque
        $connection = $this->dataset->connection;
        foreach (['commun-recree', 'commun-recree' . $this->dataset->ownerId] as $slug) {
            $this->dataset->adoption->insert('ingredient', ['slug' => $slug, 'name' => 'Commun recree', 'image' => '', 'slugged_name' => 'commun-recree', 'department' => 'sauce', 'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => null, 'compatible_units' => '[]', 'adopted_from' => null]);
        }
        $connection->executeStatement("DELETE FROM recipe_row WHERE ingredient = 'farine'");
        $connection->executeStatement("DELETE FROM shopping_list_row WHERE ingredient = 'farine'");
        $connection->executeStatement("DELETE FROM conversion WHERE ingredient = 'farine'");
        $connection->executeStatement("DELETE FROM ingredient WHERE slug = 'farine'");

        // When
        $report = $this->loader->report();

        // Then
        $this->assertSame([2, 1], [$report->unknown, $report->missing]);
        $this->assertFalse($report->matchesTheFile());
        $this->assertSame(['commun-recree'], $report->unknownSample, 'jamais un slug qui finit par un identifiant de compte');
        $this->assertSame(['farine'], $report->missingSample);
        $this->assertSame(1, $report->accountFormat);
        $this->assertSame("le classement ne correspond pas aux ingrédients sans créateur : 2 ne sont pas dans le fichier, 1 du fichier n'y sont pas", $report->blockingAnomalies[0]);
        $this->assertTrue($report->hasBlockingAnomalies());
        $this->assertFalse($report->isReadyToHandOverAfterSeeding());
    }

    public function testWithoutAnOwnerThereIsABlockingAnomalyAndNoReference(): void
    {
        // Given : le compte du propriétaire n'existe plus
        $connection = $this->dataset->connection;
        $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->dataset->ownerId]);
        static::getContainer()->get(IngredientCatalogOwner::class)->reset();
        $this->entityManager->clear();

        // When
        $report = $this->loader->report();

        // Then
        $this->assertFalse($report->ownerExists);
        $this->assertNull($report->ownerReference);
        $this->assertContains("le propriétaire du catalogue n'existe pas", $report->blockingAnomalies);
        $this->assertSame('P1', $report->unmet()[0]->code);
    }

    public function testAnEmptyCatalogThatIsNotTheOneExpectedIsBlocking(): void
    {
        // Given : une base de développement sans les anciens communs
        $connection = $this->dataset->connection;
        foreach (['recipe_row', 'shopping_list_row', 'shopping_list_ingredient'] as $table) {
            $connection->executeStatement(sprintf('DELETE FROM %s', $table));
        }
        $connection->executeStatement('DELETE FROM drive_choice_product');
        $connection->executeStatement('DELETE FROM drive_choice');
        $connection->executeStatement('DELETE FROM conversion');
        $connection->executeStatement('DELETE FROM ingredient');

        // When
        $report = $this->loader->report();

        // Then : ni « faite » ni « conforme », une anomalie bloquante le dit
        $this->assertFalse($report->handedOver);
        $this->assertFalse($report->isConform());
        $this->assertStringContainsString('le catalogue n\'est pas celui attendu', implode(' ', $report->blockingAnomalies));
    }

    public function testTheReportIsReadOnlyAndTakesNoLock(): void
    {
        // Given
        $before = $this->dataset->hashesWithoutCreator();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->report();

        // Then
        $this->assertGreaterThan(20, $counter->count());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|pg_advisory/i', $sql) === 1)));
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
    }

    public function testTheAssumedClassificationNeverOverridesAnOwnersOwnMarker(): void
    {
        // Given : un ingrédient du propriétaire créé depuis R1 et marqué « de base » par lui (il a un créateur), et un autre non marqué
        $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Mon plat de base', 'sauce');
        $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Mon plat special', 'sauce');
        $this->dataset->connection->executeStatement("UPDATE ingredient SET is_base = TRUE WHERE slug = ?", ['mon-plat-de-base' . $this->dataset->ownerId]);

        // When : le rapport lu comme si le classement était posé
        $report = $this->loader->report();

        // Then : 151 du fichier + celui que le propriétaire a marqué lui-même ; l'autre n'est pas de base
        $this->assertSame(263, $report->seeding->catalogIngredients);
        $this->assertSame(152, $report->seeding->baseIngredients);
        $this->assertSame(152 + 5, $report->seeding->selected()[0]->toSeed);
    }

    /**
     * @return array<string, HandoverPrecondition>
     */
    private function byCode(CatalogHandoverReportDTO $report): array
    {
        return array_column(array_map(static fn(HandoverPrecondition $p): array => [$p->code, $p], $report->preconditions), 1, 0);
    }
}

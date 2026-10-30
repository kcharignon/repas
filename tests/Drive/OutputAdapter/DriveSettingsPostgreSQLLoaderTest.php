<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Loader\DriveSettingsPostgreSQLLoader;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class DriveSettingsPostgreSQLLoaderTest extends DatabaseTestCase
{
    private DriveSettingsRepository $repository;
    private DriveSettingsLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(DriveSettingsRepository::class);
        $this->loader = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true));
    }

    public function testAUserWithoutSettingsGetsTheSwitchAndNoStore(): void
    {
        // When
        $result = $this->loader->get('user-1', Retailer::default());

        // Then
        $this->assertEquals(new DriveSettingsDTO(true, 'leclerc', null, [new DriveRetailerDTO('leclerc', 'Leclerc', false, null)]), $result);
    }

    public function testAUserWithSettingsGetsHisStore(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // When
        $result = $this->loader->get('user-1', Retailer::default());

        // Then : seul son magasin, avec son libellé
        $this->assertEquals(
            new DriveSettingsDTO(true, 'leclerc', $store = new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456'), [new DriveRetailerDTO('leclerc', 'Leclerc', true, $store)]),
            $result,
        );
        $this->assertNull($this->loader->get('user-3', Retailer::default())->store);
    }

    public function testOnlyTheEnabledRetailersAreListedAndTheOtherFieldsDoNotChange(): void
    {
        // Given : un magasin enregistré, aucune enseigne allumée
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $loader = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true, []));

        // When
        $result = $loader->get('user-1', Retailer::default());

        // Then : aucune entrée dans `retailers` (DRV-702), le reste est ce qu'il était
        $this->assertSame([], $result->retailers);
        $this->assertTrue($result->enabled);
        $this->assertSame('leclerc', $result->retailer);
        $this->assertSame('123456', $result->store?->id);

        // When : on rallume l'enseigne
        $again = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true, [Retailer::default()->value]))->get('user-1', Retailer::default());

        // Then : ses données étaient restées
        $this->assertCount(1, $again->retailers);
        $this->assertTrue($again->retailers[0]->configured);
    }

    public function testAStoreWithoutLabelHasANullLabel(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // Then
        $this->assertNull($this->loader->get('user-1', Retailer::default())->store->label);
    }

    public function testTheSwitchComesFromTheDriveFeature(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $loader = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(false));

        // When
        $result = $loader->get('user-1', Retailer::default());

        // Then : coupé, la lecture répond quand même, avec le magasin
        $this->assertFalse($result->enabled);
        $this->assertNotNull($result->store);
    }

    public function testTheRowsOfAnotherRetailerAreIgnoredAndTheStoreIsThatOfTheRequestedOne(): void
    {
        // Given : un réglage chez une enseigne que ce code ne connaît pas (autre déploiement, enseigne retirée), posé directement
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_settings (user_id, retailer, store_id, store_label, base_url, updated_at, product_writes_count) VALUES ('user-1', 'autre-test', '999', NULL, 'https://exemple.invalid/magasin-999', NOW(), 0)",
        );
        $this->entityManager->clear();

        // When
        $result = $this->loader->get('user-1', Retailer::default());

        // Then : seul le magasin de l'enseigne demandée, une seule entrée par enseigne connue
        $this->assertSame('123456', $result->store?->id);
        $this->assertCount(1, $result->retailers);
        $this->assertSame('123456', $result->retailers[0]->store?->id);
    }

    public function testReadingCostsOneQuery(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->get('user-1', Retailer::default());

        // Then
        $this->assertSame(['SELECT drive_settings' => 1], $counter->byTable());
    }
}

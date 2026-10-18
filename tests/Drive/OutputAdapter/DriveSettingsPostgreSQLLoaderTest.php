<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
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
        $result = $this->loader->get('user-1');

        // Then
        $this->assertEquals(new DriveSettingsDTO(true, 'leclerc', null), $result);
    }

    public function testAUserWithSettingsGetsHisStore(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // When
        $result = $this->loader->get('user-1');

        // Then : seul son magasin, avec son libellé
        $this->assertEquals(
            new DriveSettingsDTO(true, 'leclerc', new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456')),
            $result,
        );
        $this->assertNull($this->loader->get('user-3')->store);
    }

    public function testAStoreWithoutLabelHasANullLabel(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // Then
        $this->assertNull($this->loader->get('user-1')->store->label);
    }

    public function testTheSwitchComesFromTheDriveFeature(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $loader = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(false));

        // When
        $result = $loader->get('user-1');

        // Then : coupé, la lecture répond quand même, avec le magasin
        $this->assertFalse($result->enabled);
        $this->assertNotNull($result->store);
    }

    public function testReadingCostsOneQuery(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->get('user-1');

        // Then
        $this->assertSame(['SELECT drive_settings' => 1], $counter->byTable());
    }
}

<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class DriveSettingsRepositoryTest extends DatabaseTestCase
{
    private DriveSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(DriveSettingsRepository::class);
    }

    public function testASavedSettingIsReadBackWithAllItsValues(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->withUpdatedAt(new DateTimeImmutable('2026-10-18 10:30:00'))->build();
        $this->repository->save($settings);
        $this->entityManager->clear();

        // When
        $found = $this->repository->findOneByUser('user-1', Retailer::LECLERC);

        // Then
        $this->assertNotNull($found);
        $this->assertTrue($settings->isEqual($found));
        $this->assertEquals($settings->getStore(), $found->getStore());
        $this->assertEquals($settings->getUpdatedAt(), $found->getUpdatedAt());
    }

    public function testAUserWithoutSettingsGetsNull(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();

        // Then
        $this->assertNull($this->repository->findOneByUser('user-2', Retailer::LECLERC));
    }

    public function testSavingAgainReplacesTheStoreInTheSameRow(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $settings = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $new = new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build();

        // When
        $this->repository->save($settings->changeStore($new->getStore(), new DateTimeImmutable('2026-10-19 08:00:00')));
        $this->entityManager->clear();

        // Then
        $found = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertEquals($new->getStore(), $found->getStore());
        $this->assertEquals(new DateTimeImmutable('2026-10-19 08:00:00'), $found->getUpdatedAt());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings WHERE user_id = ?', ['user-1']));
    }

    public function testSettingsOfTwoUsersAreIndependent(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());
        $this->entityManager->clear();

        // Then
        $this->assertSame('123456', $this->repository->findOneByUser('user-1', Retailer::LECLERC)->getStore()->id);
        $this->assertSame('654321', $this->repository->findOneByUser('user-2', Retailer::LECLERC)->getStore()->id);
    }

    public function testDeleteByUserErasesHisSettingsOnlyEvenWhenAlreadyLoaded(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('departing')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('staying')->build());
        $this->repository->findOneByUser('departing', Retailer::LECLERC);

        // When
        $this->repository->deleteByUser('departing');

        // Then : relu sans vider l'identity map, la ligne supprimée n'est plus rendue
        $this->assertNull($this->repository->findOneByUser('departing', Retailer::LECLERC));
        $this->assertNotNull($this->repository->findOneByUser('staying', Retailer::LECLERC));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testSavingAnExistingSettingCostsOneSelectAndOneUpdate(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $settings = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->repository->save($settings->changeStore($settings->getStore(), new DateTimeImmutable('2026-10-19 08:00:00')));

        // Then
        $this->assertSame(['SELECT drive_settings' => 1, 'UPDATE drive_settings' => 1], $counter->byTable());
    }
}

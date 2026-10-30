<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
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

    public function testTheCounterOfProductWritesIsReadBackAndSurvivesAStoreChange(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->build());
        $this->entityManager->clear();

        // When
        $this->assertTrue($this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 42, $now));
        $this->entityManager->clear();
        $settings = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->repository->save($settings->changeStore(Store::create(Retailer::LECLERC, '654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321'), $now));
        $this->entityManager->clear();

        // Then
        $found = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame([42, '2026-10-19'], [$found->getProductWritesCount(), $found->getProductWritesDay()->format('Y-m-d')]);
        $this->assertSame('654321', $found->getStore()->id);
        $untouched = $this->repository->findOneByUser('user-2', Retailer::LECLERC);
        $this->assertSame(0, $untouched->getProductWritesCount());
        $this->assertNull($untouched->getProductWritesDay());
    }

    public function testReservingWritesCountsThemForTheDayAndAddsUp(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // When
        $first = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 40, $now);
        $second = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 2, $now->modify('+3 hours'));
        $this->entityManager->clear();

        // Then
        $this->assertSame([true, true], [$first, $second]);
        $found = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame([42, '2026-10-19'], [$found->getProductWritesCount(), $found->getProductWritesDay()->format('Y-m-d')]);
    }

    public function testReservingWritesIsRefusedAboveTheDailyLimitAndCountsNothing(): void
    {
        // Given : 4 990 écritures aujourd'hui
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withProductWrites($now, 4990)->build());

        // When : 11 de trop, puis 10 qui tiennent exactement, puis une de plus
        $tooMany = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 11, $now);
        $exactly = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 10, $now);
        $oneMore = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 1, $now);
        $this->entityManager->clear();

        // Then
        $this->assertSame([false, true, false], [$tooMany, $exactly, $oneMore]);
        $this->assertSame(5000, $this->repository->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount());
    }

    public function testTheDailyLimitIsPerUserAndPerRetailer(): void
    {
        // Given : le même compte a atteint son plafond du jour chez une AUTRE enseigne (ligne posée directement : le code de production
        // n'en connaît qu'une, la seconde n'existe que dans ce test) (DRV-701, décision D-M5)
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_settings (user_id, retailer, store_id, store_label, base_url, updated_at, product_writes_day, product_writes_count)"
            . " VALUES ('user-1', 'autre-test', '999', NULL, 'https://exemple.invalid/magasin-999', NOW(), '2026-10-19', 5000)",
        );
        $this->entityManager->clear();

        // When : chez la première enseigne, le plafond entier reste disponible, puis il est atteint
        $whole = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, DriveSettings::MAX_PRODUCT_WRITES_PER_DAY, $now);
        $oneMore = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 1, $now);

        // Then : chaque enseigne a son compteur (la borne d'un compte est 5 000 par enseigne)
        $this->assertSame([true, false], [$whole, $oneMore]);
        $counts = $this->entityManager->getConnection()->fetchAllKeyValue("SELECT retailer, product_writes_count FROM drive_settings WHERE user_id = 'user-1'");
        $this->assertEquals(['leclerc' => 5000, 'autre-test' => 5000], $counts);
    }

    public function testTheCounterStartsAgainEachUtcDay(): void
    {
        // Given : le plafond du 19 est atteint
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withProductWrites(new DateTimeImmutable('2026-10-19'), 5000)->build());

        // When : 23 h 30 UTC le 19, puis 02 h 00 à Paris le 20 (00 h 00 UTC)
        $sameDay = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 1, new DateTimeImmutable('2026-10-19 23:30:00+00:00'));
        $nextDay = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 5000, new DateTimeImmutable('2026-10-20 02:00:00+02:00'));
        $this->entityManager->clear();

        // Then
        $this->assertSame([false, true], [$sameDay, $nextDay]);
        $found = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame([5000, '2026-10-20'], [$found->getProductWritesCount(), $found->getProductWritesDay()->format('Y-m-d')]);
    }

    public function testReservingWritesJudgesTheValueInTheDatabaseNotTheOneAModelWasReadWith(): void
    {
        // Given : deux requêtes du même compte lisent les réglages (compteur à 0), la première réserve 4 000, l'autre 2 000 avec sa lecture périmée
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $staleRead = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame(0, $staleRead->getProductWritesCount());
        $this->entityManager->getConnection()->executeStatement("UPDATE drive_settings SET product_writes_day = '2026-10-19', product_writes_count = 4000 WHERE user_id = 'user-1'");

        // When : les 2 000 d'une lecture à zéro auraient tenu ; ajoutés aux 4 000 déjà comptés ils dépassent 5 000
        $reserved = $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 2000, $now);

        // Then
        $this->assertFalse($reserved);
        $this->assertSame(4000, (int) $this->entityManager->getConnection()->fetchOne("SELECT product_writes_count FROM drive_settings WHERE user_id = 'user-1'"));
    }

    public function testReservingWritesForUnknownSettingsIsRefused(): void
    {
        // Then
        $this->assertFalse($this->repository->reserveProductWrites('nobody', Retailer::LECLERC, 1, new DateTimeImmutable('2026-10-19 08:00:00')));
    }

    public function testReservingWritesTouchesOnlyTheCounterOfTheUser(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->build());

        // When
        $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 7, $now);
        $this->entityManager->clear();

        // Then
        $this->assertSame([7, 0], [
            $this->repository->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount(),
            $this->repository->findOneByUser('user-2', Retailer::LECLERC)->getProductWritesCount(),
        ]);
    }

    public function testReservingWritesCostsOneUpdate(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->reserveProductWrites('user-1', Retailer::LECLERC, 3, new DateTimeImmutable('2026-10-19 08:00:00'));

        // Then
        $this->assertSame(['UPDATE drive_settings' => 1], $counter->byTable());
    }

    public function testSavingTheSettingsAgainNeverRewindsTheCounter(): void
    {
        // Given : un modèle lu avant que le compteur ne bouge, enregistré après (un changement de magasin concurrent)
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $staleRead = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->entityManager->getConnection()->executeStatement("UPDATE drive_settings SET product_writes_day = '2026-10-19', product_writes_count = 4000 WHERE user_id = 'user-1'");

        // When
        $this->repository->save($staleRead->changeStore($staleRead->getStore(), $now));
        $this->entityManager->clear();

        // Then
        $this->assertSame(4000, $this->repository->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount());
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

    public function testDeleteRemovesOnlyTheSettingOfThatRetailerEvenWhenAlreadyLoaded(): void
    {
        // Given : deux enseignes pour la même personne (DRV-703), le réglage d'une autre personne, et la ligne déjà chargée
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::LECLERC)->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::AUCHAN)->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->withRetailer(Retailer::LECLERC)->build());
        $this->repository->findOneByUser('user-1', Retailer::LECLERC);

        // When
        $deleted = $this->repository->delete('user-1', Retailer::LECLERC);
        $deletedAgain = $this->repository->delete('user-1', Retailer::LECLERC);

        // Then : relu sans vider l'identity map, la ligne supprimée n'est plus rendue ; l'autre enseigne et l'autre personne restent
        $this->assertSame([true, false], [$deleted, $deletedAgain]);
        $this->assertNull($this->repository->findOneByUser('user-1', Retailer::LECLERC));
        $this->assertNotNull($this->repository->findOneByUser('user-1', Retailer::AUCHAN));
        $this->assertNotNull($this->repository->findOneByUser('user-2', Retailer::LECLERC));
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testDeletingASettingThatNeverExistedDeletesNothing(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // Then
        $this->assertFalse($this->repository->delete('nobody', Retailer::LECLERC));
        $this->assertFalse($this->repository->delete('user-1', Retailer::AUCHAN));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testDeletingASettingCostsOneStatement(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->delete('user-1', Retailer::LECLERC);

        // Then
        $this->assertSame(['DELETE drive_settings' => 1], $counter->byTable());
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

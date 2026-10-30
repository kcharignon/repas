<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class RetailerPreferenceRepositoryTest extends DatabaseTestCase
{
    private RetailerPreferenceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(RetailerPreferenceRepository::class);
    }

    public function testASavedPreferenceIsReadBackWithAllItsValues(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $this->repository->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::AUCHAN, $now)->rememberLastUsed(Retailer::LECLERC, $now));
        $this->entityManager->clear();

        // When
        $found = $this->repository->findOneByUser('user-1');

        // Then
        $this->assertNotNull($found);
        $this->assertSame(['user-1', Retailer::AUCHAN, Retailer::LECLERC], [$found->getUserId(), $found->getDefaultRetailer(), $found->getLastUsedRetailer()]);
        $this->assertEquals($now, $found->getUpdatedAt());
    }

    public function testAPreferenceWithOnlyOneValueReadsTheOtherAsNull(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $this->repository->save(RetailerPreference::create('user-1', $now)->rememberLastUsed(Retailer::LECLERC, $now));

        // When
        $found = $this->repository->findOneByUser('user-1');

        // Then
        $this->assertSame([null, Retailer::LECLERC], [$found?->getDefaultRetailer(), $found?->getLastUsedRetailer()]);
    }

    public function testAUserWithoutPreferenceGetsNull(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $this->repository->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::LECLERC, $now));

        // Then
        $this->assertNull($this->repository->findOneByUser('user-2'));
    }

    public function testSavingAgainReplacesTheValuesInTheSameRowAndANullClearsOne(): void
    {
        // Given
        $first = new DateTimeImmutable('2026-10-30 10:30:00');
        $later = new DateTimeImmutable('2026-10-30 18:00:00');
        $this->repository->save(RetailerPreference::create('user-1', $first)->chooseDefault(Retailer::LECLERC, $first)->rememberLastUsed(Retailer::LECLERC, $first));

        // When : le drive habituel est effacé, la dernière enseigne change
        $this->repository->save($this->repository->findOneByUser('user-1')->chooseDefault(null, $later)->rememberLastUsed(Retailer::AUCHAN, $later));

        // Then : une seule ligne
        $found = $this->repository->findOneByUser('user-1');
        $this->assertSame([null, Retailer::AUCHAN], [$found?->getDefaultRetailer(), $found?->getLastUsedRetailer()]);
        $this->assertEquals($later, $found?->getUpdatedAt());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_retailer_preference'));
    }

    public function testTheReadIsNeverStaleAfterASaveBecauseNothingIsKeptInTheIdentityMap(): void
    {
        // Given : la lecture, puis une écriture, puis une relecture sans vider quoi que ce soit
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $this->repository->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::LECLERC, $now));
        $this->repository->findOneByUser('user-1');

        // When
        $this->repository->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::AUCHAN, $now));

        // Then
        $this->assertSame(Retailer::AUCHAN, $this->repository->findOneByUser('user-1')?->getDefaultRetailer());
    }

    public function testAnUnknownRetailerOfAnOtherVersionReadsAsAbsent(): void
    {
        // Given : une valeur d'une enseigne retirée du registre, posée directement
        $this->entityManager->getConnection()->executeStatement("INSERT INTO drive_retailer_preference (user_id, default_retailer, last_used_retailer, updated_at) VALUES ('user-1', 'autre-test', 'leclerc', NOW())");

        // When
        $found = $this->repository->findOneByUser('user-1');

        // Then
        $this->assertSame([null, Retailer::LECLERC], [$found?->getDefaultRetailer(), $found?->getLastUsedRetailer()]);
    }

    public function testDeleteByUserErasesHisPreferenceOnly(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $this->repository->save(RetailerPreference::create('departing', $now)->chooseDefault(Retailer::LECLERC, $now));
        $this->repository->save(RetailerPreference::create('staying', $now)->chooseDefault(Retailer::LECLERC, $now));
        $this->repository->findOneByUser('departing');

        // When
        $this->repository->deleteByUser('departing');
        $this->repository->deleteByUser('nobody');

        // Then
        $this->assertNull($this->repository->findOneByUser('departing'));
        $this->assertNotNull($this->repository->findOneByUser('staying'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_retailer_preference'));
    }

    public function testEachOperationCostsOneStatement(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:30:00');
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When / Then : lire, enregistrer (création, puis remplacement) et supprimer
        $this->repository->findOneByUser('user-1');
        $this->assertSame(['SELECT drive_retailer_preference' => 1], $counter->byTable());
        $counter->reset();
        $this->repository->save(RetailerPreference::create('user-1', $now));
        $this->repository->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::LECLERC, $now));
        $this->assertSame(['INSERT drive_retailer_preference' => 2], $counter->byTable());
        $counter->reset();
        $this->repository->deleteByUser('user-1');
        $this->assertSame(['DELETE drive_retailer_preference' => 1], $counter->byTable());
    }
}

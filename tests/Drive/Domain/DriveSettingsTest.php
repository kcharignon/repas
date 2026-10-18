<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;

class DriveSettingsTest extends TestCase
{
    private const string URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';

    public function testCreateKeepsTheUserTheRetailerTheStoreAndTheDate(): void
    {
        // Given
        $store = Store::create(Retailer::LECLERC, '123456', 'Ville-Test', self::URL);
        $now = new DateTimeImmutable('2026-10-18 12:00:00');

        // When
        $settings = DriveSettings::create('user-1', Retailer::LECLERC, $store, $now);

        // Then
        $this->assertSame(['user-1', Retailer::LECLERC, $store, $now], [$settings->getUserId(), $settings->getRetailer(), $settings->getStore(), $settings->getUpdatedAt()]);
        $this->assertSame('user-1:leclerc', $settings->getId());
    }

    public function testChangeStoreReplacesTheStoreAndRefreshesTheDate(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->build();
        $other = Store::create(Retailer::LECLERC, '654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321');
        $now = new DateTimeImmutable('2026-10-19 08:00:00');

        // When
        $result = $settings->changeStore($other, $now);

        // Then
        $this->assertSame($settings, $result);
        $this->assertSame([$other, $now], [$settings->getStore(), $settings->getUpdatedAt()]);
        $this->assertSame('user-1', $settings->getUserId());
    }

    public function testTheProductWritesOfTheDayAreCountedUpToTheLimit(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->build();
        $now = new DateTimeImmutable('2026-10-19 08:00:00');

        // When
        $result = $settings->recordProductWrites(4990, $now)->recordProductWrites(10, $now->modify('+10 hours'));

        // Then : le plafond lui-même (5 000) est permis
        $this->assertSame($settings, $result);
        $this->assertSame([DriveSettings::MAX_PRODUCT_WRITES_PER_DAY, '2026-10-19'], [$settings->getProductWritesCount(), $settings->getProductWritesDay()->format('Y-m-d')]);
    }

    public function testOneWriteBeyondTheLimitIsRefusedAndNothingIsCounted(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->build();
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $settings->recordProductWrites(4999, $now);

        // When / Then
        try {
            $settings->recordProductWrites(2, $now);
            $this->fail('DRIVE_LIMIT_REACHED attendu');
        } catch (DriveException $exception) {
            $this->assertSame(['DRIVE_LIMIT_REACHED', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(4999, $settings->getProductWritesCount());
        // Un lot plus gros que le plafond est refusé même le premier jour
        $this->expectExceptionObject(DriveException::limitReached());
        new DriveSettingsBuilder()->build()->recordProductWrites(5001, $now);
    }

    public function testTheCounterStartsOverEachDayUtc(): void
    {
        // Given : le plafond atteint le 19 à 23 h 30 UTC
        $settings = new DriveSettingsBuilder()->build();
        $settings->recordProductWrites(5000, new DateTimeImmutable('2026-10-19 23:30:00+00:00'));

        // When / Then : à Paris il est déjà le 20 (00 h 30), mais c'est encore le 19 UTC : toujours refusé
        $this->assertSame(true, $this->isRefused($settings, new DateTimeImmutable('2026-10-20 00:30:00+02:00')));

        // When : minuit UTC passé (02 h 00 à Paris)
        $settings->recordProductWrites(1, new DateTimeImmutable('2026-10-20 02:00:00+02:00'));

        // Then
        $this->assertSame([1, '2026-10-20'], [$settings->getProductWritesCount(), $settings->getProductWritesDay()->format('Y-m-d')]);
    }

    private function isRefused(DriveSettings $settings, DateTimeImmutable $now): bool
    {
        try {
            $settings->recordProductWrites(1, $now);
        } catch (DriveException) {
            return true;
        }

        return false;
    }

    public function testChangingTheStoreKeepsTheCounter(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-19 08:00:00');
        $settings = new DriveSettingsBuilder()->build()->recordProductWrites(4000, $now);

        // When
        $settings->changeStore(Store::create(Retailer::LECLERC, '654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321'), $now);

        // Then
        $this->assertSame(4000, $settings->getProductWritesCount());
    }

    public function testTwoSettingsOfTheSameUserAndRetailerAreEqual(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->build();

        // Then
        $this->assertTrue($settings->isEqual(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build()));
        $this->assertFalse($settings->isEqual(new DriveSettingsBuilder()->withUserId('user-2')->build()));
    }
}

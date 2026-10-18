<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
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

    public function testTwoSettingsOfTheSameUserAndRetailerAreEqual(): void
    {
        // Given
        $settings = new DriveSettingsBuilder()->withUserId('user-1')->build();

        // Then
        $this->assertTrue($settings->isEqual(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build()));
        $this->assertFalse($settings->isEqual(new DriveSettingsBuilder()->withUserId('user-2')->build()));
    }
}

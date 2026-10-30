<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\RemoveDriveSettings\RemoveDriveSettingsCommand;
use Repas\Drive\Application\Command\RemoveDriveSettings\RemoveDriveSettingsHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RetailerPreferenceInMemoryRepository;

class RemoveDriveSettingsHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private DriveSettingsInMemoryRepository $settings;
    private RetailerPreferenceInMemoryRepository $preferences;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-30 12:00:00');
        $this->settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::LECLERC)->build(),
            new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::AUCHAN)->build(),
            new DriveSettingsBuilder()->withUserId('user-2')->withRetailer(Retailer::LECLERC)->build(),
        ]);
        $this->preferences = new RetailerPreferenceInMemoryRepository();
    }

    public function testTheSettingsOfTheRetailerAreRemovedAndTheOthersKept(): void
    {
        // When
        $this->handler()(new RemoveDriveSettingsCommand('user-1', Retailer::AUCHAN));

        // Then : ni l'autre enseigne de la personne, ni le réglage d'une autre personne
        $this->assertNull($this->settings->findOneByUser('user-1', Retailer::AUCHAN));
        $this->assertNotNull($this->settings->findOneByUser('user-1', Retailer::LECLERC));
        $this->assertNotNull($this->settings->findOneByUser('user-2', Retailer::LECLERC));
    }

    public function testTheDefaultAndTheLastRetailerThatPointedToItAreClearedAndNothingElse(): void
    {
        // Given : le drive habituel est celui qu'on retire, la dernière enseigne l'autre
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::AUCHAN, $this->now)->rememberLastUsed(Retailer::LECLERC, $this->now));

        // When
        $this->handler()(new RemoveDriveSettingsCommand('user-1', Retailer::AUCHAN));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([null, Retailer::LECLERC], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
    }

    public function testAPreferenceThatDoesNotPointToTheRetailerIsNotRewritten(): void
    {
        // Given
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now));
        $writes = $this->preferences->saveCount;

        // When
        $this->handler()(new RemoveDriveSettingsCommand('user-1', Retailer::AUCHAN));

        // Then
        $this->assertSame($writes, $this->preferences->saveCount);
        $this->assertSame(Retailer::LECLERC, $this->preferences->findOneByUser('user-1')?->getDefaultRetailer());
    }

    public function testRemovingWithoutAnyPreferenceCreatesNone(): void
    {
        // When
        $this->handler()(new RemoveDriveSettingsCommand('user-1', Retailer::LECLERC));

        // Then
        $this->assertSame([], $this->preferences->all());
    }

    public function testAnUnconfiguredRetailerIsNotFound(): void
    {
        // Given : le retrait est refusé et rien n'est touché
        $handler = $this->handler();

        // Then
        $this->expectExceptionObject(DriveException::settingsNotFound());

        // When
        try {
            $handler(new RemoveDriveSettingsCommand('user-3', Retailer::LECLERC));
        } finally {
            $this->assertCount(3, $this->settings->all());
        }
    }

    public function testADisabledRetailerIsRefusedAndItsSettingsKept(): void
    {
        // Given
        $handler = $this->handler(new ControlledDriveFeature(true, ['leclerc']));

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            $handler(new RemoveDriveSettingsCommand('user-1', Retailer::AUCHAN));
        } finally {
            $this->assertNotNull($this->settings->findOneByUser('user-1', Retailer::AUCHAN));
        }
    }

    private function handler(?ControlledDriveFeature $feature = null): RemoveDriveSettingsHandler
    {
        return new RemoveDriveSettingsHandler($this->settings, $this->preferences, new FrozenClock($this->now), $feature ?? new ControlledDriveFeature());
    }
}

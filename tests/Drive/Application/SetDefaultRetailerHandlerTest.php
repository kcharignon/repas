<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\SetDefaultRetailer\SetDefaultRetailerCommand;
use Repas\Drive\Application\Command\SetDefaultRetailer\SetDefaultRetailerHandler;
use Repas\Drive\Application\Service\ConfiguredRetailerGuard;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RetailerPreferenceInMemoryRepository;

class SetDefaultRetailerHandlerTest extends TestCase
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
        ]);
        $this->preferences = new RetailerPreferenceInMemoryRepository();
    }

    public function testTheFirstChoiceCreatesThePreferenceWithTheDefaultRetailerOnly(): void
    {
        // When
        $this->handler()(new SetDefaultRetailerCommand('user-1', Retailer::AUCHAN));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([Retailer::AUCHAN, null], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
        $this->assertEquals($this->now, $preference?->getUpdatedAt());
    }

    public function testChangingTheDefaultKeepsTheLastRetailer(): void
    {
        // Given
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now)->rememberLastUsed(Retailer::AUCHAN, $this->now));

        // When
        $this->handler()(new SetDefaultRetailerCommand('user-1', Retailer::AUCHAN));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([Retailer::AUCHAN, Retailer::AUCHAN], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
    }

    public function testNullClearsTheDefaultRetailerAndKeepsTheLastOne(): void
    {
        // Given
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now)->rememberLastUsed(Retailer::AUCHAN, $this->now));

        // When
        $this->handler()(new SetDefaultRetailerCommand('user-1', null));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([null, Retailer::AUCHAN], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
    }

    public function testClearingWhenThereIsNothingToClearWritesNothing(): void
    {
        // When : ni préférence, ni drive habituel
        $this->handler()(new SetDefaultRetailerCommand('user-1', null));
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->rememberLastUsed(Retailer::AUCHAN, $this->now));
        $writes = $this->preferences->saveCount;
        $this->handler()(new SetDefaultRetailerCommand('user-1', null));

        // Then : aucune ligne créée pour rien, aucun enregistrement quand il n'y a rien à effacer
        $this->assertSame($writes, $this->preferences->saveCount);
    }

    public function testChoosingTheSameDefaultAgainWritesNothing(): void
    {
        // Given
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now));
        $writes = $this->preferences->saveCount;

        // When
        $this->handler()(new SetDefaultRetailerCommand('user-1', Retailer::LECLERC));

        // Then
        $this->assertSame($writes, $this->preferences->saveCount);
    }

    public function testARetailerWithoutAStoreIsRefusedAndNothingIsWritten(): void
    {
        // Given : un autre utilisateur a un magasin, pas celui-ci
        $handler = $this->handler();

        // Then
        $this->expectExceptionObject(DriveException::retailerNotConfigured());

        // When
        try {
            $handler(new SetDefaultRetailerCommand('user-2', Retailer::LECLERC));
        } finally {
            $this->assertSame([], $this->preferences->all());
        }
    }

    public function testADisabledRetailerIsRefusedEvenWhenItHasAStore(): void
    {
        // Given : l'enseigne a un magasin mais elle est éteinte (DRV-702)
        $handler = $this->handler(new ControlledDriveFeature(true, ['leclerc']));

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            $handler(new SetDefaultRetailerCommand('user-1', Retailer::AUCHAN));
        } finally {
            $this->assertSame([], $this->preferences->all());
        }
    }

    public function testClearingTheDefaultNeedsNoRetailerAtAll(): void
    {
        // Given : plus aucune enseigne allumée, la personne efface quand même son drive habituel
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now));

        // When
        $this->handler(new ControlledDriveFeature(true, []))(new SetDefaultRetailerCommand('user-1', null));

        // Then
        $this->assertNull($this->preferences->findOneByUser('user-1')?->getDefaultRetailer());
    }

    private function handler(?ControlledDriveFeature $feature = null): SetDefaultRetailerHandler
    {
        return new SetDefaultRetailerHandler($this->preferences, new ConfiguredRetailerGuard($feature ?? new ControlledDriveFeature(), $this->settings), new FrozenClock($this->now));
    }
}

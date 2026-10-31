<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\RememberLastRetailer\RememberLastRetailerCommand;
use Repas\Drive\Application\Command\RememberLastRetailer\RememberLastRetailerHandler;
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

class RememberLastRetailerHandlerTest extends TestCase
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

    public function testTheFirstOpeningCreatesThePreferenceWithTheLastRetailerOnly(): void
    {
        // When
        $this->handler()(new RememberLastRetailerCommand('user-1', Retailer::AUCHAN));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([null, Retailer::AUCHAN], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
    }

    public function testOpeningAnotherRetailerReplacesTheLastOneAndKeepsTheDefault(): void
    {
        // Given
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->chooseDefault(Retailer::LECLERC, $this->now)->rememberLastUsed(Retailer::LECLERC, $this->now));

        // When
        $this->handler()(new RememberLastRetailerCommand('user-1', Retailer::AUCHAN));

        // Then
        $preference = $this->preferences->findOneByUser('user-1');
        $this->assertSame([Retailer::LECLERC, Retailer::AUCHAN], [$preference?->getDefaultRetailer(), $preference?->getLastUsedRetailer()]);
    }

    public function testOpeningTheSameRetailerAgainWritesNothing(): void
    {
        // Given : le cas de presque tous les passages
        $this->preferences->save(RetailerPreference::create('user-1', $this->now)->rememberLastUsed(Retailer::LECLERC, $this->now));
        $writes = $this->preferences->saveCount;

        // When
        $this->handler()(new RememberLastRetailerCommand('user-1', Retailer::LECLERC));

        // Then
        $this->assertSame($writes, $this->preferences->saveCount);
    }

    public function testARetailerWithoutAStoreIsRefusedAndNothingIsWritten(): void
    {
        // Given
        $handler = $this->handler();

        // Then
        $this->expectExceptionObject(DriveException::retailerNotConfigured());

        // When
        try {
            $handler(new RememberLastRetailerCommand('user-2', Retailer::LECLERC));
        } finally {
            $this->assertSame([], $this->preferences->all());
        }
    }

    public function testADisabledRetailerIsRefusedEvenWhenItHasAStore(): void
    {
        // Given
        $handler = $this->handler(new ControlledDriveFeature(true, ['leclerc']));

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            $handler(new RememberLastRetailerCommand('user-1', Retailer::AUCHAN));
        } finally {
            $this->assertSame([], $this->preferences->all());
        }
    }

    private function handler(?ControlledDriveFeature $feature = null): RememberLastRetailerHandler
    {
        return new RememberLastRetailerHandler($this->preferences, new ConfiguredRetailerGuard($feature ?? new ControlledDriveFeature(), $this->settings), new FrozenClock($this->now));
    }
}

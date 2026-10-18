<?php

namespace Repas\Tests\Drive\Application;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataCommand;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;

class PurgeUserDriveDataHandlerTest extends TestCase
{
    public function testTheSettingsAndChoicesOfTheDeletedUserAreErasedAndTheOthersKept(): void
    {
        // Given
        $settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('departing')->build(),
            new DriveSettingsBuilder()->withUserId('staying')->build(),
        ]);
        $choices = new ChoiceInMemoryRepository([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('farine')->build(),
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('sel')->build(),
            new ChoiceBuilder()->withUserId('staying')->build(),
        ]);

        // When
        new PurgeUserDriveDataHandler($settings, $choices)(new PurgeUserDriveDataCommand('departing'));

        // Then
        $this->assertNull($settings->findOneByUser('departing', Retailer::LECLERC));
        $this->assertNotNull($settings->findOneByUser('staying', Retailer::LECLERC));
        $this->assertSame(['staying'], array_map(static fn($choice) => $choice->getUserId(), $choices->all()));
        $this->assertSame(['departing'], $choices->productsDeletedForUsers);
    }

    public function testAUserWithoutDriveDataIsNotAnError(): void
    {
        // Given
        $settings = new DriveSettingsInMemoryRepository();
        $choices = new ChoiceInMemoryRepository();

        // When
        new PurgeUserDriveDataHandler($settings, $choices)(new PurgeUserDriveDataCommand('nobody'));

        // Then
        $this->assertSame([], $settings->all());
        $this->assertSame([], $choices->all());
    }
}

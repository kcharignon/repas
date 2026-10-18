<?php

namespace Repas\Tests\Drive\Application;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataCommand;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;

class PurgeUserDriveDataHandlerTest extends TestCase
{
    public function testTheSettingsOfTheDeletedUserAreErasedAndTheOthersKept(): void
    {
        // Given
        $repository = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('departing')->build(),
            new DriveSettingsBuilder()->withUserId('staying')->build(),
        ]);

        // When
        new PurgeUserDriveDataHandler($repository)(new PurgeUserDriveDataCommand('departing'));

        // Then
        $this->assertNull($repository->findOneByUser('departing', Retailer::LECLERC));
        $this->assertNotNull($repository->findOneByUser('staying', Retailer::LECLERC));
    }

    public function testAUserWithoutSettingsIsNotAnError(): void
    {
        // Given
        $repository = new DriveSettingsInMemoryRepository();

        // When
        new PurgeUserDriveDataHandler($repository)(new PurgeUserDriveDataCommand('nobody'));

        // Then
        $this->assertSame([], $repository->all());
    }
}

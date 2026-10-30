<?php

namespace Repas\Tests\Drive\Application\Query\GetDrivePlan;


use Repas\Shared\Domain\Exception\DriveFeatureException;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Builder\DTO\DrivePlanDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\DriveSettingsDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DrivePlanInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\DriveSettingsInMemoryLoader;

class GetDrivePlanQueryHandlerTest extends TestCase
{
    public function testTransmitsTheRequestedRetailerToTheLoaderWithoutReadingTheSettings(): void
    {
        // Given
        $loader = new DrivePlanInMemoryLoader(new DrivePlanDTOBuilder()->build());
        $settings = new DriveSettingsInMemoryLoader();

        // When
        new GetDrivePlanQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDrivePlanQuery('user-42', 'list-1', Retailer::LECLERC));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::LECLERC]], $loader->getCalls);
        $this->assertSame([], $settings->getCalls);
    }

    public function testReturnsWhatTheLoaderReturnsForTheRetailerOfTheSelectionWhenTheRequestNamesNone(): void
    {
        // Given : la sélection de l'utilisateur désigne une enseigne (DRV-703)
        $plan = new DrivePlanDTOBuilder()->build();
        $loader = new DrivePlanInMemoryLoader($plan);
        $settings = new DriveSettingsInMemoryLoader(new DriveSettingsDTOBuilder()->withRetailer('auchan')->build());

        // When
        $result = new GetDrivePlanQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDrivePlanQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($plan, $result);
        $this->assertSame([['user-42', 'list-1', Retailer::AUCHAN]], $loader->getCalls);
        $this->assertSame([['user-42', null]], $settings->getCalls);
    }

    public function testAnUnconfiguredUserStillAsksTheLoaderForTheDefaultRetailerAsBefore(): void
    {
        // Given : aucune enseigne configurée, la sélection rend l'enseigne par défaut : le loader répondra `409 DRIVE_NOT_CONFIGURED`
        $loader = new DrivePlanInMemoryLoader(new DrivePlanDTOBuilder()->build());
        $settings = new DriveSettingsInMemoryLoader(new DriveSettingsDTOBuilder()->unconfigured()->build());

        // When
        new GetDrivePlanQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDrivePlanQuery('user-42', 'list-1'));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::default()]], $loader->getCalls);
    }

    public function testADisabledRetailerReadsNothingEvenWhenItIsTheDefaultOne(): void
    {
        // Given : l'enseigne par défaut n'est pas allumée (DRV-702)
        $loader = new DrivePlanInMemoryLoader(new DrivePlanDTOBuilder()->build());

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            new GetDrivePlanQueryHandler($loader, new ControlledDriveFeature(true, []), new DriveSettingsInMemoryLoader())(new GetDrivePlanQuery('user-42', 'list-1'));
        } finally {
            $this->assertSame([], $loader->getCalls);
        }
    }
}

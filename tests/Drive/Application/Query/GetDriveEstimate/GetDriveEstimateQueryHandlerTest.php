<?php

namespace Repas\Tests\Drive\Application\Query\GetDriveEstimate;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Builder\DTO\DriveEstimateDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\DriveSettingsDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DriveEstimateInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\DriveSettingsInMemoryLoader;

class GetDriveEstimateQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRetailerOfTheSelectionWhenTheRequestNamesNone(): void
    {
        // Given : la sélection de l'utilisateur désigne une enseigne (DRV-703)
        $estimate = new DriveEstimateDTOBuilder()->build();
        $loader = new DriveEstimateInMemoryLoader($estimate);
        $settings = new DriveSettingsInMemoryLoader(new DriveSettingsDTOBuilder()->withRetailer('auchan')->build());

        // When
        $result = new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDriveEstimateQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($estimate, $result);
        $this->assertSame([['user-42', 'list-1', Retailer::AUCHAN]], $loader->getCalls);
        $this->assertSame([['user-42', null]], $settings->getCalls);
    }

    public function testAnUnconfiguredUserStillAsksTheLoaderForTheDefaultRetailerAsBefore(): void
    {
        // Given : aucune enseigne configurée, la sélection rend l'enseigne par défaut : le loader répondra `409 DRIVE_NOT_CONFIGURED`
        $loader = new DriveEstimateInMemoryLoader(new DriveEstimateDTOBuilder()->build());
        $settings = new DriveSettingsInMemoryLoader(new DriveSettingsDTOBuilder()->unconfigured()->build());

        // When
        new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDriveEstimateQuery('user-42', 'list-1'));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::default()]], $loader->getCalls);
    }

    public function testTransmitsTheRequestedRetailerToTheLoaderWithoutReadingTheSettings(): void
    {
        // Given
        $loader = new DriveEstimateInMemoryLoader(new DriveEstimateDTOBuilder()->build());
        $settings = new DriveSettingsInMemoryLoader();

        // When
        new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature(), $settings)(new GetDriveEstimateQuery('user-42', 'list-1', Retailer::LECLERC));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::LECLERC]], $loader->getCalls);
        $this->assertSame([], $settings->getCalls);
    }

    public function testADisabledRetailerReadsNothing(): void
    {
        // Given : l'enseigne demandée n'est pas allumée (DRV-702)
        $loader = new DriveEstimateInMemoryLoader(new DriveEstimateDTOBuilder()->build());

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature(true, []), new DriveSettingsInMemoryLoader())(new GetDriveEstimateQuery('user-42', 'list-1', Retailer::LECLERC));
        } finally {
            $this->assertSame([], $loader->getCalls);
        }
    }
}

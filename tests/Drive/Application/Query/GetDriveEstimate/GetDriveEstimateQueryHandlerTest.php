<?php

namespace Repas\Tests\Drive\Application\Query\GetDriveEstimate;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Builder\DTO\DriveEstimateDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DriveEstimateInMemoryLoader;

class GetDriveEstimateQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUserAndList(): void
    {
        // Given
        $estimate = new DriveEstimateDTOBuilder()->build();
        $loader = new DriveEstimateInMemoryLoader($estimate);

        // When
        $result = new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature())(new GetDriveEstimateQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($estimate, $result);
        $this->assertSame([['user-42', 'list-1', Retailer::default()]], $loader->getCalls);
    }

    public function testTransmitsTheRequestedRetailerToTheLoader(): void
    {
        // Given
        $loader = new DriveEstimateInMemoryLoader(new DriveEstimateDTOBuilder()->build());

        // When
        new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature())(new GetDriveEstimateQuery('user-42', 'list-1', Retailer::LECLERC));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::LECLERC]], $loader->getCalls);
    }

    public function testADisabledRetailerReadsNothing(): void
    {
        // Given : l'enseigne demandée n'est pas allumée (DRV-702)
        $loader = new DriveEstimateInMemoryLoader(new DriveEstimateDTOBuilder()->build());

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            new GetDriveEstimateQueryHandler($loader, new ControlledDriveFeature(true, []))(new GetDriveEstimateQuery('user-42', 'list-1', Retailer::LECLERC));
        } finally {
            $this->assertSame([], $loader->getCalls);
        }
    }
}

<?php

namespace Repas\Tests\Drive\Application\Query\GetDriveEstimate;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQueryHandler;
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
        $result = new GetDriveEstimateQueryHandler($loader)(new GetDriveEstimateQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($estimate, $result);
        $this->assertSame([['user-42', 'list-1']], $loader->getCalls);
    }
}

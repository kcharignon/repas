<?php

namespace Repas\Tests\Drive\Application\Query\GetDrivePlan;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\DTO\DrivePlanDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DrivePlanInMemoryLoader;

class GetDrivePlanQueryHandlerTest extends TestCase
{
    public function testTransmitsTheRequestedRetailerToTheLoader(): void
    {
        // Given
        $loader = new DrivePlanInMemoryLoader(new DrivePlanDTOBuilder()->build());

        // When
        new GetDrivePlanQueryHandler($loader)(new GetDrivePlanQuery('user-42', 'list-1', Retailer::LECLERC));

        // Then
        $this->assertSame([['user-42', 'list-1', Retailer::LECLERC]], $loader->getCalls);
    }

    public function testReturnsWhatTheLoaderReturnsForTheRequestedUserAndList(): void
    {
        // Given
        $plan = new DrivePlanDTOBuilder()->build();
        $loader = new DrivePlanInMemoryLoader($plan);

        // When
        $result = new GetDrivePlanQueryHandler($loader)(new GetDrivePlanQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($plan, $result);
        $this->assertSame([['user-42', 'list-1', Retailer::default()]], $loader->getCalls);
    }
}

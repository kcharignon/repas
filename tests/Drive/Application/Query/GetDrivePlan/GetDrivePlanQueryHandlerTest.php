<?php

namespace Repas\Tests\Drive\Application\Query\GetDrivePlan;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQueryHandler;
use Repas\Tests\Helper\Builder\DTO\DrivePlanDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DrivePlanInMemoryLoader;

class GetDrivePlanQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUserAndList(): void
    {
        // Given
        $plan = new DrivePlanDTOBuilder()->build();
        $loader = new DrivePlanInMemoryLoader($plan);

        // When
        $result = new GetDrivePlanQueryHandler($loader)(new GetDrivePlanQuery('user-42', 'list-1'));

        // Then
        $this->assertSame($plan, $result);
        $this->assertSame([['user-42', 'list-1']], $loader->getCalls);
    }
}

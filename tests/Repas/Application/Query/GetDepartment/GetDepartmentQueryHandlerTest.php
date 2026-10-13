<?php

namespace Repas\Tests\Repas\Application\Query\GetDepartment;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetDepartment\GetDepartmentQuery;
use Repas\Repas\Application\Query\GetDepartment\GetDepartmentQueryHandler;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DepartmentInMemoryLoader;

class GetDepartmentQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $department = new DepartmentDTOBuilder()->build();
        $handler = new GetDepartmentQueryHandler(new DepartmentInMemoryLoader(department: $department));

        // When
        $result = $handler(new GetDepartmentQuery('slug-1'));

        // Then
        $this->assertSame($department, $result);
    }

    public function testForwardsTheSlugToTheLoader(): void
    {
        // Given
        $loader = new DepartmentInMemoryLoader(department: new DepartmentDTOBuilder()->build());
        $handler = new GetDepartmentQueryHandler($loader);

        // When
        $handler(new GetDepartmentQuery('slug-1'));

        // Then
        $this->assertSame(['slug-1'], $loader->getCalls);
    }
}

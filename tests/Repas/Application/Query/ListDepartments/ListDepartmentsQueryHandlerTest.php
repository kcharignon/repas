<?php

namespace Repas\Tests\Repas\Application\Query\ListDepartments;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQueryHandler;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DepartmentInMemoryLoader;

class ListDepartmentsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $departments = [
            new DepartmentDTOBuilder()->withSlug('boulangerie')->build(),
            new DepartmentDTOBuilder()->withSlug('fromage')->build(),
        ];
        $loader = new DepartmentInMemoryLoader($departments);
        $handler = new ListDepartmentsQueryHandler($loader);

        // When
        $result = $handler(new ListDepartmentsQuery());

        // Then
        $this->assertSame($departments, $result);
        $this->assertSame(1, $loader->listCalls);
    }

    public function testReturnsEmptyListWhenLoaderHasNoDepartment(): void
    {
        // Given
        $handler = new ListDepartmentsQueryHandler(new DepartmentInMemoryLoader());

        // When
        $result = $handler(new ListDepartmentsQuery());

        // Then
        $this->assertSame([], $result);
    }
}

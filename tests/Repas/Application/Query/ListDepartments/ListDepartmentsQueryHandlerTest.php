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
            new DepartmentDTOBuilder()->withSlug('boulangerie')->buildListItem(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withListTypeId('type-1')->buildListItem(),
        ];
        $loader = new DepartmentInMemoryLoader($departments);
        $handler = new ListDepartmentsQueryHandler($loader);

        // When
        $result = $handler(new ListDepartmentsQuery('user-1'));

        // Then
        $this->assertSame($departments, $result);
        $this->assertSame(1, $loader->listCalls);
        $this->assertSame(['user-1'], $loader->listUsers);
    }

    public function testReturnsEmptyListWhenLoaderHasNoDepartment(): void
    {
        // Given
        $handler = new ListDepartmentsQueryHandler(new DepartmentInMemoryLoader());

        // When
        $result = $handler(new ListDepartmentsQuery('user-1'));

        // Then
        $this->assertSame([], $result);
    }
}

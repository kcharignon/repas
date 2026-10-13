<?php

namespace Repas\Tests\Repas\Application\Query\ListIngredientsByDepartment;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQueryHandler;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\IngredientInMemoryLoader;

class ListIngredientsByDepartmentQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $result = new DepartmentIngredientsDTO(new DepartmentDTOBuilder()->build(), [new IngredientRowDTO('farine', 'farine', 'g', true)]);
        $handler = new ListIngredientsByDepartmentQueryHandler(new IngredientInMemoryLoader(departmentIngredients: $result));

        // When
        $actual = $handler(new ListIngredientsByDepartmentQuery('cereale', 'user-1', false));

        // Then
        $this->assertSame($result, $actual);
    }

    public function testForwardsTheDepartmentAndTheViewerToTheLoader(): void
    {
        // Given
        $loader = new IngredientInMemoryLoader(departmentIngredients: new DepartmentIngredientsDTO(new DepartmentDTOBuilder()->build(), []));
        $handler = new ListIngredientsByDepartmentQueryHandler($loader);

        // When
        $handler(new ListIngredientsByDepartmentQuery('cereale', 'user-1', true));

        // Then
        $this->assertSame([['cereale', 'user-1', true]], $loader->listByDepartmentCalls);
    }
}

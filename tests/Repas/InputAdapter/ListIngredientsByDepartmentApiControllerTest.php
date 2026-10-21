<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListIngredientsByDepartmentApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheDepartmentAndItsIngredients(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListIngredientsByDepartmentQuery::class, new DepartmentIngredientsDTO(
            new DepartmentDTOBuilder()->withSlug('cereale')->withName('céréale')->withImage('images/cereale.svg')->build(),
            [new IngredientRowDTO('pate', 'pâtes', 'g', true, true), new IngredientRowDTO('riz', 'riz', 'g', false, false)],
        ));

        // When
        $this->client->request('GET', '/api/v1/departments/cereale/ingredients', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListIngredientsByDepartmentQuery::class, $query);
        $this->assertSame(['cereale', $user->getId()], [$query->departmentSlug, $query->viewerId]);
        $this->assertSame([
            'department' => ['slug' => 'cereale', 'name' => 'céréale', 'image' => 'images/cereale.svg'],
            'member' => [
                ['slug' => 'pate', 'name' => 'pâtes', 'purchaseUnitSymbol' => 'g', 'canEdit' => true, 'isBase' => true],
                ['slug' => 'riz', 'name' => 'riz', 'purchaseUnitSymbol' => 'g', 'canEdit' => false, 'isBase' => false],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testAnAdministratorIsAskedLikeAnyoneElse(): void
    {
        // Given : plus de cas particulier pour l'administrateur (ADR 0028, amendement) : la question ne porte que le rayon et le lecteur
        $this->queryBus->willReturn(ListIngredientsByDepartmentQuery::class, new DepartmentIngredientsDTO(new DepartmentDTOBuilder()->build(), []));
        $admin = $this->savedUser(admin: true);

        // When
        $this->client->request('GET', '/api/v1/departments/cereale/ingredients', server: $this->bearerFor($admin));

        // Then
        $query = $this->queryBus->getAskedQuery(ListIngredientsByDepartmentQuery::class);
        $this->assertEquals(new ListIngredientsByDepartmentQuery('cereale', $admin->getId()), $query);
    }

    public function testReturns404ForAnUnknownDepartment(): void
    {
        // Given
        $this->queryBus->willThrow(ListIngredientsByDepartmentQuery::class, DepartmentException::notFound());

        // When
        $this->client->request('GET', '/api/v1/departments/inconnu/ingredients', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/departments/cereale/ingredients');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

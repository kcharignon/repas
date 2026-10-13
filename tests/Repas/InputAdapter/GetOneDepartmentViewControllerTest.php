<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testPageShowsTheIngredientsWithTheirQuantityInTheActiveList(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListIngredientsByDepartmentQuery::class, new DepartmentIngredientsDTO(
            new DepartmentDTOBuilder()->withSlug('cereale')->withName('céréale')->build(),
            [new IngredientRowDTO('pate', 'pâtes', 'g', true), new IngredientRowDTO('riz', 'riz', 'g', false)],
        ));
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withQuantity('pate', 2.5)->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/department/cereale');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getAskedQuery(ListIngredientsByDepartmentQuery::class);
        $this->assertSame(['cereale', $user->getId(), false], [$query->departmentSlug, $query->viewerId, $query->viewerIsAdmin]);
        $this->assertSame($user->getId(), $this->queryBus->getAskedQuery(GetActiveShoppingListSummaryQuery::class)->ownerId);
        $this->assertSame('Céréale', trim($crawler->filter('li.active')->text()));
        $this->assertSame('2.5 g', trim($crawler->filter('#ingredient-row-pate button[disabled]')->text()));
        $this->assertCount(0, $crawler->filter('#ingredient-row-riz button[disabled]'));
        $this->assertSame('/ingredient/pate', $crawler->filter('#ingredient-row-pate a.btn-secondary')->attr('href'));
        $this->assertCount(0, $crawler->filter('#ingredient-row-riz a.btn-secondary'));
    }

    public function testAnAdministratorIsAskedAsSuch(): void
    {
        // Given
        $this->queryBus->willReturn(ListIngredientsByDepartmentQuery::class, new DepartmentIngredientsDTO(new DepartmentDTOBuilder()->build(), []));
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('GET', '/department/cereale');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->queryBus->getAskedQuery(ListIngredientsByDepartmentQuery::class)->viewerIsAdmin);
    }

    public function testPageShowsNoQuantityWithoutActiveList(): void
    {
        // Given
        $this->queryBus->willReturn(ListIngredientsByDepartmentQuery::class, new DepartmentIngredientsDTO(
            new DepartmentDTOBuilder()->withSlug('cereale')->build(),
            [new IngredientRowDTO('pate', 'pâtes', 'g', false)],
        ));
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/department/cereale');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $crawler->filter('#ingredient-row-pate'));
        $this->assertCount(0, $crawler->filter('#ingredient-row-pate button[disabled]'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/department/cereale');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

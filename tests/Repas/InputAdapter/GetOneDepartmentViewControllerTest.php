<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testPageShowsTheQuantityOfTheIngredientsOfTheActiveList(): void
    {
        // Given
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $rice = new IngredientBuilder()->withName('riz')->withDepartment(new DepartmentBuilder()->isCereal())->build();
        $this->persister->saveIngredient($pasta);
        $this->persister->saveIngredient($rice);
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withQuantity($pasta->getSlug(), 2.5)->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/department/' . $pasta->getDepartment()->getSlug());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame('2.5 g', trim($crawler->filter('#ingredient-row-' . $pasta->getSlug() . ' button[disabled]')->text()));
        $this->assertCount(0, $crawler->filter('#ingredient-row-' . $rice->getSlug() . ' button[disabled]'));
    }

    public function testPageShowsNoQuantityWithoutActiveList(): void
    {
        // Given
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/department/' . $pasta->getDepartment()->getSlug());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $crawler->filter('#ingredient-row-' . $pasta->getSlug()));
        $this->assertCount(0, $crawler->filter('#ingredient-row-' . $pasta->getSlug() . ' button[disabled]'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/department/cereal');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testPageMarksTheRecipesPlannedInTheActiveList(): void
    {
        // Given
        $user = $this->savedUser();
        $planned = new RecipeBuilder()->withName('zzz recette planifiée')->withAuthor($user)->build();
        $notPlanned = new RecipeBuilder()->withName('zzz recette libre')->withAuthor($user)->build();
        $this->persister->saveRecipe($planned);
        $this->persister->saveRecipe($notPlanned);
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withPlannedRecipe($planned->getId())->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipe/type/' . $planned->getType()->getSlug());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $plannedRow = $crawler->filter('#recipe_' . $planned->getId());
        $this->assertCount(1, $plannedRow->filter('.fa-check-square'));
        $this->assertSame('/shopping-list/active/recipe/' . $planned->getId() . '/remove', $plannedRow->filter('[data-action="btn-ajax"]')->attr('data-url'));
        $freeRow = $crawler->filter('#recipe_' . $notPlanned->getId());
        $this->assertCount(1, $freeRow->filter('.fa-square'));
        $this->assertCount(0, $freeRow->filter('.fa-check-square'));
        $this->assertSame('/shopping-list/active/recipe/' . $notPlanned->getId() . '/add', $freeRow->filter('[data-action="btn-ajax"]')->attr('data-url'));
    }

    public function testNoRecipeIsMarkedWithoutActiveList(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($user)->build();
        $this->persister->saveRecipe($recipe);
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipe/type/' . $recipe->getType()->getSlug());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('.fa-check-square'));
        $this->assertCount(1, $crawler->filter('#recipe_' . $recipe->getId() . ' .fa-square'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/recipe/type/plat');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

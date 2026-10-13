<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQuery;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testPageListsTheRecipesOfTheTypeAndMarksThePlannedOnes(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListRecipesByTypeQuery::class, new RecipeTypeRecipesDTO(
            new RecipeTypeDTOBuilder()->withSlug('plat')->withName('plat')->build(),
            [new RecipeSummaryDTO('recipe-planned', 'zzz recette planifiée'), new RecipeSummaryDTO('recipe-free', 'zzz recette libre')],
        ));
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withPlannedRecipe('recipe-planned')->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipe/type/plat');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Plat', trim($crawler->filter('li.active')->text()));
        $this->assertSame(['Zzz recette planifiée', 'Zzz recette libre'], array_map(
            static fn(string $text): string => trim($text),
            $crawler->filter('li[id^="recipe_"] div[data-action="btn-ajax"]')->extract(['_text']),
        ));
        $plannedRow = $crawler->filter('#recipe_recipe-planned');
        $this->assertCount(1, $plannedRow->filter('.fa-check-square'));
        $this->assertSame('/shopping-list/active/recipe/recipe-planned/remove', $plannedRow->filter('[data-action="btn-ajax"]')->attr('data-url'));
        $freeRow = $crawler->filter('#recipe_recipe-free');
        $this->assertCount(1, $freeRow->filter('.fa-square'));
        $this->assertCount(0, $freeRow->filter('.fa-check-square'));
        $this->assertSame('/shopping-list/active/recipe/recipe-free/add', $freeRow->filter('[data-action="btn-ajax"]')->attr('data-url'));
    }

    public function testAsksTheRecipesOfTheConnectedUserForTheRequestedType(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListRecipesByTypeQuery::class, new RecipeTypeRecipesDTO(new RecipeTypeDTOBuilder()->withSlug('dessert')->withName('dessert')->build(), []));
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipe/type/dessert');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $recipesQuery = $this->queryBus->getAskedQuery(ListRecipesByTypeQuery::class);
        $this->assertSame([$user->getId(), 'dessert'], [$recipesQuery->authorId, $recipesQuery->typeSlug]);
        $this->assertSame($user->getId(), $this->queryBus->getAskedQuery(GetActiveShoppingListSummaryQuery::class)->ownerId);
        $this->assertCount(0, $crawler->filter('.fa-check-square'));
        $this->assertStringContainsString("Vous n'avez pas encore de recette de ce type.", $crawler->text());
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

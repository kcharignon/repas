<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Tests\Helper\Builder\DTO\RecipeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneRecipeViewControllerTest extends QueryBusWebTestCase
{
    public function testPageShowsTheRecipeAndItsIngredients(): void
    {
        // Given
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()
            ->withId('recipe-1')
            ->withName('pâtes carbonara')
            ->withServing(4)
            ->withRow(new RecipeRowDTO('pate', 'pâtes', 'images/pate.png', 500.0, 'gramme', 'g'))
            ->withRow(new RecipeRowDTO('oeuf', 'œuf', '', 2.5, 'unite', ''))
            ->build());
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/recipe/recipe-1');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertSame('recipe-1', $query->recipeId);
        $this->assertSame('Pâtes carbonara', trim($crawler->filter('h1')->text()));
        $this->assertSame('Ingrédients pour 4 personnes', trim($crawler->filter('li.active')->text()));
        $normalize = static fn(string $text): string => trim(preg_replace('/\s+/', ' ', $text));
        $rows = $crawler->filter('li.list-group-item:not(.active)');
        $this->assertCount(2, $rows);
        $this->assertSame('500g de Pâtes', $normalize($rows->eq(0)->text()));
        $this->assertStringEndsWith('images/pate.png', $rows->eq(0)->filter('img')->attr('src'));
        $this->assertSame('2.5 Œuf', $normalize($rows->eq(1)->text()));
        $this->assertCount(0, $rows->eq(1)->filter('img'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/recipe/recipe-1');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

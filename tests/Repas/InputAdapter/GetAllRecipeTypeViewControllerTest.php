<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetAllRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testPageRendersOneCardPerRecipeTypeFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListRecipeTypesQuery::class, [
            new RecipeTypeDTOBuilder()->withSlug('entree')->withName('entrée')->withImage('images/entree.svg')->withOrder(1)->build(),
            new RecipeTypeDTOBuilder()->withSlug('plat')->withName('plat')->withImage('images/plat.svg')->withOrder(2)->build(),
        ]);
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/recipes');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListRecipeTypesQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame(['Entrée', 'Plat', 'Nouvelle recette'], array_map('trim', $crawler->filter('.card-header')->extract(['_text'])));
        $this->assertStringStartsWith('/recipe/type/entree', $crawler->filter('.card a')->eq(0)->attr('href'));
        $this->assertStringEndsWith('images/plat.svg', $crawler->filter('.card img')->eq(1)->attr('src'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/recipes');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

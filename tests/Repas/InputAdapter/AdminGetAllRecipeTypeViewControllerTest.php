<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminGetAllRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testPageRendersOneRowPerRecipeTypeFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListRecipeTypesQuery::class, [
            new RecipeTypeDTOBuilder()->withSlug('entree')->withName('entrée')->withImage('images/entree.svg')->withOrder(1)->build(),
            new RecipeTypeDTOBuilder()->withSlug('plat')->withName('plat')->withImage('images/plat.svg')->withOrder(2)->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/recipe-type');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListRecipeTypesQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame(
            ['Plat', '2', 'images/plat.svg'],
            array_map('trim', $crawler->filter('#department-plat td')->slice(0, 3)->extract(['_text'])),
        );
        $this->assertSame('/admin/recipe-type/plat/update', $crawler->filter('#department-plat a')->attr('href'));
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/recipe-type');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

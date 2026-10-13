<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipeType\GetRecipeTypeQuery;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminUpdateRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheRecipeTypeDTO(): void
    {
        // Given
        $this->queryBus->willReturn(GetRecipeTypeQuery::class, new RecipeTypeDTOBuilder()->withSlug('zzz-brunch')->withName('zzz brunch')->withImage('file://images/zzz.svg')->withOrder(7)->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/recipe-type/zzz-brunch/update');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeTypeQuery::class, $query);
        $this->assertSame('zzz-brunch', $query->slug);
        $this->assertStringContainsString('Modification de zzz brunch', $crawler->filter('h1')->text());
        $this->assertSame('zzz brunch', $crawler->filter('input[name="update_recipe_type[name]"]')->attr('value'));
        $this->assertSame('file://images/zzz.svg', $crawler->filter('input[name="update_recipe_type[image]"]')->attr('value'));
        $this->assertSame('7', $crawler->filter('input[name="update_recipe_type[order]"]')->attr('value'));
    }

    public function testValidSubmissionUpdatesTheRecipeTypeThenRedirectsToTheList(): void
    {
        // Given
        $repository = static::getContainer()->get(RecipeTypeRepository::class);
        $repository->save(new RecipeTypeBuilder()->withSlug('zzz-brunch')->withName('zzz brunch')->withImage('file://images/zzz.svg')->withOrder(7)->build());
        $this->queryBus->willReturn(GetRecipeTypeQuery::class, new RecipeTypeDTOBuilder()->withSlug('zzz-brunch')->withName('zzz brunch')->withImage('file://images/zzz.svg')->withOrder(7)->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('POST', '/admin/recipe-type/zzz-brunch/update', [
            'update_recipe_type' => ['name' => 'zzz goûter', 'image' => 'file://images/zzz2.svg', 'order' => '8', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/admin/recipe-type'));
        $saved = $repository->findOneBySlug('zzz-brunch');
        $this->assertSame(['zzz goûter', 8], [$saved->getName(), $saved->getOrder()]);
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/recipe-type/plat/update');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

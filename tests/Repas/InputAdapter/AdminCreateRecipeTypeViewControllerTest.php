<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminCreateRecipeTypeViewControllerTest extends QueryBusWebTestCase
{
    public function testValidSubmissionCreatesTheTypeThenRedirectsToTheList(): void
    {
        // Given
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('POST', '/admin/recipe-type/create', [
            'create_recipe_type' => ['name' => 'Zzz goûter', 'image' => 'https://exemple.org/zzz.svg', 'order' => '6', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/admin/recipe-type'));
        $this->assertSame('Zzz goûter', static::getContainer()->get(RecipeTypeRepository::class)->findOneBySlug('zzz-gouter')->getName());
    }

    public function testADuplicateNameShowsAnErrorAndKeepsTheFirstType(): void
    {
        // Given
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->withName('Zzz goûter')->withImage('https://exemple.org/premier.svg')->withOrder(3)->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('POST', '/admin/recipe-type/create', [
            'create_recipe_type' => ['name' => 'zzz gouter', 'image' => 'https://exemple.org/second.svg', 'order' => '9', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Un type de recette de ce nom existe déjà.', $crawler->text());
        $this->assertSame(3, static::getContainer()->get(RecipeTypeRepository::class)->findOneBySlug('zzz-gouter')->getOrder());
    }
}

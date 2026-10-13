<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateRecipeViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsDisplayed(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/recipe');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Créer une nouvelle recette', trim($crawler->filter('h2')->text()));
        $this->assertCount(1, $crawler->filter('input[name="create_recipe[name]"]'));
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testValidSubmissionCreatesTheRecipeThenRedirectsToItsType(): void
    {
        // Given
        $user = $this->savedUser();
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/recipe', [
            'create_recipe' => [
                'name' => 'zzz mousse',
                'serving' => '4',
                'typeSlug' => 'dessert',
                'rows' => [['ingredientSlug' => 'oeuf', 'quantity' => '6', 'unitSlug' => 'unite']],
                '_token' => 'csrf-token',
            ],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/recipe/type/dessert'));
        $recipes = static::getContainer()->get(RecipeRepository::class)->findByAuthor($user);
        $this->assertCount(1, $recipes);
        $this->assertSame(['zzz mousse', 4], [$recipes->reset()->getName(), $recipes->reset()->getServing()]);
    }
}

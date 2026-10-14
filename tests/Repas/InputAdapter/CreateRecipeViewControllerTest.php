<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateRecipeViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsDisplayed(): void
    {
        // Given
        $this->offerOptions();
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/recipe');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Créer une nouvelle recette', trim($crawler->filter('h2')->text()));
        $this->assertCount(1, $crawler->filter('input[name="create_recipe[name]"]'));
        $this->assertNull($this->queryBus->getAskedQuery(GetRecipeQuery::class));
    }

    public function testValidSubmissionCreatesTheRecipeThenRedirectsToItsType(): void
    {
        // Given
        $user = $this->savedUser();
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->offerOptions();
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
        $recipes = static::getContainer()->get(RecipeRepository::class)->findByAuthor($user->getId());
        $this->assertCount(1, $recipes);
        $this->assertSame(['zzz mousse', 4], [$recipes->reset()->getName(), $recipes->reset()->getServing()]);
    }

    private function offerOptions(): void
    {
        $this->willOfferFormOptions(
            units: [new UnitDTOBuilder()->withSlug('unite')->withName('unité')->build()],
            recipeTypes: [new RecipeTypeDTOBuilder()->withSlug('dessert')->withName('dessert')->build()],
            ingredients: [new IngredientOptionDTO('oeuf', 'œuf')],
        );
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\DTO\RecipeDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateRecipeViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheRecipeDTO(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = $this->savedRecipeOf($user);
        $this->queryBus->willReturn(GetRecipeQuery::class, $this->recipeDTO($recipe->getId()));
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipe/' . $recipe->getId() . '/update');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertSame($recipe->getId(), $query->recipeId);
        $this->assertSame('Zzz omelette', trim($crawler->filter('h2')->text()));
        $this->assertSame('zzz omelette', $crawler->filter('input[name="update_recipe[name]"]')->attr('value'));
        $this->assertSame('6', $crawler->filter('input[name="update_recipe[serving]"]')->attr('value'));
        $this->assertSame('plat', $crawler->filter('select[name="update_recipe[typeSlug]"] option[selected]')->attr('value'));
        $this->assertCount(1, $crawler->filter('#recipe-rows ul > li'));
        $this->assertSame('3', $crawler->filter('input[name="update_recipe[rows][0][quantity]"]')->attr('value'));
        $this->assertSame('oeuf', $crawler->filter('select[name="update_recipe[rows][0][ingredientSlug]"] option[selected]')->attr('value'));
        $this->assertSame('unite', $crawler->filter('select[name="update_recipe[rows][0][unitSlug]"] option[selected]')->attr('value'));
    }

    public function testValidSubmissionUpdatesTheRecipeThenRedirectsToItsType(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = $this->savedRecipeOf($user);
        $this->queryBus->willReturn(GetRecipeQuery::class, $this->recipeDTO($recipe->getId()));
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/recipe/' . $recipe->getId() . '/update', [
            'update_recipe' => [
                'name' => 'zzz omelette modifiée',
                'serving' => '2',
                'typeSlug' => 'plat',
                'rows' => [['ingredientSlug' => 'oeuf', 'quantity' => '4', 'unitSlug' => 'unite']],
                '_token' => 'csrf-token',
            ],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/recipe/type/plat'));
        $saved = static::getContainer()->get(RecipeRepository::class)->findOneById($recipe->getId());
        $this->assertSame(['zzz omelette modifiée', 2], [$saved->getName(), $saved->getServing()]);
        $this->assertSame(4.0, $saved->getRows()->reset()->getQuantity());
    }

    public function testRefusesARecipeOfAnotherUser(): void
    {
        // Given
        $recipe = $this->savedRecipeOf($this->savedUser());
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/recipe/' . $recipe->getId() . '/update');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    private function savedRecipeOf(User $user): Recipe
    {
        $recipe = new RecipeBuilder()->withAuthor($user)->withName('zzz omelette')->withServing(6)->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isEgg())->withUnit(new UnitBuilder()->isUnite())->withQuantity(3))
            ->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function recipeDTO(string $id): RecipeDTO
    {
        return new RecipeDTOBuilder()->withId($id)->withName('zzz omelette')->withServing(6)->withType('plat', 'plat')
            ->withRow(new RecipeRowDTO('oeuf', 'œuf', '', 3.0, 'unite', ''))
            ->build();
    }
}

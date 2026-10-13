<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AddRecipeToActiveShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testPlansTheRecipeThenReturnsTheRowFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $recipe = new RecipeBuilder()->withAuthor($user)->withName('zzz omelette')->addRow(
            new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isEgg())->withUnit(new UnitBuilder()->isUnite())->withQuantity(2)
        )->build();
        $this->persister->saveRecipe($recipe);
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withPlannedRecipe($recipe->getId())->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/active/recipe/' . $recipe->getId() . '/add');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $active = static::getContainer()->get(ShoppingListRepository::class)->findOneActivateByOwner($user);
        $this->assertTrue($active->hasRecipe($recipe));
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#recipe_' . $recipe->getId(), $view['selector']);
        $this->assertStringContainsString('fa-check-square', $view['html']);
        $this->assertStringContainsString('/shopping-list/active/recipe/' . $recipe->getId() . '/remove', $view['html']);
    }
}

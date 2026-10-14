<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingListByMeal\GetShoppingListByMealQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\ShoppingListRowDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class UpdateServingMealViewControllerTest extends QueryBusWebTestCase
{
    public function testUpdatesTheServingThenReturnsTheIngredientsColumnFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($user)->withName('zzz omelette')->addRow(
            new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isEgg())->withUnit(new UnitBuilder()->isUnite())->withQuantity(2)
        )->build();
        $this->persister->saveRecipe($recipe);
        $shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        $shoppingListRepository->save($shoppingList);
        /** @var Meal $meal */
        $meal = $shoppingList->getMeals()->reset();
        $this->queryBus->willReturn(GetShoppingListByMealQuery::class, new ShoppingListDetailDTOBuilder()
            ->withDepartment(new ShoppingListDepartmentDTO('divers', 'divers', 'images/divers.svg', [
                new ShoppingListRowDTOBuilder()->withId('row-egg')->withIngredientName('œuf')->withQuantity(12)->build(),
            ]))
            ->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('PUT', '/shopping-list/meal/' . $meal->getId() . '/serving/8');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(8, $shoppingListRepository->findOneById($shoppingList->getId())->getMeals()->reset()->getServing());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListByMealQuery::class, $query);
        $this->assertSame($meal->getId(), $query->mealId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#shoppingListIngredientsColumn', $view['selector']);
        $this->assertStringContainsString('1 Ingredients', $view['html']);
        $this->assertStringContainsString('Œuf', $view['html']);
        $this->assertStringContainsString('12', $view['html']);
    }

    public function testRefusesAMealOfAnotherUser(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('PUT', '/shopping-list/meal/' . $shoppingList->getMeals()->reset()->getId() . '/serving/8');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAChangeOpenedByGet(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/shopping-list/meal/any-meal/serving/8');

        // Then
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveRecipeToActiveShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testRemovesTheRecipeThenReturnsTheRowFromTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($user)->withName('zzz omelette')->build();
        $this->persister->saveRecipe($recipe);
        $shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build());
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/active/recipe/' . $recipe->getId() . '/remove');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($shoppingListRepository->findOneActivateByOwner($user)->hasRecipe($recipe));
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $view = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['views'][0];
        $this->assertSame('#recipe_' . $recipe->getId(), $view['selector']);
        $this->assertStringNotContainsString('fa-check-square', $view['html']);
        $this->assertStringContainsString('/shopping-list/active/recipe/' . $recipe->getId() . '/add', $view['html']);
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListMealDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRecipeTypeDTO;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\ShoppingListRowDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetOneShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testPageShowsTheListFromTheDTO(): void
    {
        // Given
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetShoppingListQuery::class, new ShoppingListDetailDTOBuilder()
            ->withId($shoppingList->getId())
            ->withName('Courses du week-end')
            ->withDepartment(new ShoppingListDepartmentDTO('cereale', 'céréale', 'images/cereale.svg', [
                new ShoppingListRowDTOBuilder()->withId('row-pasta')->withIngredientName('pâtes')->withQuantity(500.2)->withUnitSymbol('g')->build(),
            ]))
            ->withDepartment(new ShoppingListDepartmentDTO('divers', 'divers', 'images/divers.svg', [
                new ShoppingListRowDTOBuilder()->withId('row-egg')->withIngredientName('œuf')->withQuantity(6)->checked()->build(),
            ]))
            ->withRecipeType(new ShoppingListRecipeTypeDTO('plat', 'plat', 'images/plat.svg', [
                new ShoppingListMealDTO('meal-1', 'recipe-1', 'pâtes carbonara', 4),
                new ShoppingListMealDTO('meal-2', 'recipe-2', 'omelette', 2),
            ]))
            ->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/shopping-list/' . $shoppingList->getId());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListQuery::class, $query);
        $this->assertSame($shoppingList->getId(), $query->shoppingListId);
        $this->assertSame('Courses du week-end', trim($crawler->filter('#card-header-name')->text()));
        $this->assertStringContainsString('12 octobre 2026', $crawler->filter('title')->text());
        $this->assertSame('2 Ingrédients', trim($crawler->filter('#button-show-ingredients')->text()));
        $this->assertSame('2 Recettes', trim($crawler->filter('#button-show-recipes')->text()));
        $this->assertSame(['Céréale', 'Divers'], array_map('trim', $crawler->filter('#shoppingListIngredientsColumn ul > li.active')->extract(['_text'])));
        $pasta = $crawler->filter('#shopping_list_row_row-pasta');
        $this->assertSame('Pâtes', trim($pasta->filter('span')->eq(0)->text()));
        $this->assertSame('501 g', trim(preg_replace('/\s+/', ' ', $pasta->filter('.badge')->text())));
        $this->assertSame('/shopping-list/line/row-pasta/tick', $pasta->attr('data-url'));
        $egg = $crawler->filter('#shopping_list_row_row-egg');
        $this->assertSame('/shopping-list/line/row-egg/uncheck', $egg->attr('data-url'));
        $this->assertCount(1, $egg->filter('.fa-check-square'));
        $this->assertSame(['2 Plats'], array_map(static fn(string $text): string => trim(preg_replace('/\s+/', ' ', $text)), $crawler->filter('#shopping-list-recipes-column ul > li.active')->extract(['_text'])));
        $this->assertSame('/shopping-list/meal/meal-1/serving/__serving__', $crawler->filter('#meal-1')->attr('data-url'));
        $this->assertSame('4', $crawler->filter('#meal-1')->attr('value'));
        $this->assertStringContainsString('Omelette', $crawler->filter('#shopping-list-recipes-column')->text());
    }

    public function testPageShowsTheDefaultNameOfAListWithoutName(): void
    {
        // Given
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetShoppingListQuery::class, new ShoppingListDetailDTOBuilder()->withId($shoppingList->getId())->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/shopping-list/' . $shoppingList->getId());

        // Then
        $this->assertSame('Liste du 12 octobre 2026', trim($crawler->filter('#card-header-name')->text()));
        $this->assertSame('', $crawler->filter('#card-header-input')->attr('data-value'));
        $this->assertCount(0, $crawler->filter('#shoppingListIngredientsColumn ul'));
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $shoppingList = $this->savedShoppingList($this->savedUser());
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/shopping-list/' . $shoppingList->getId());

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/shopping-list/any-id');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

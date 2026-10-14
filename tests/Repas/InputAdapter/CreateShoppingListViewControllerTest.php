<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateShoppingListViewControllerTest extends QueryBusWebTestCase
{
    public function testCreatesTheListThenRedirectsToIt(): void
    {
        // Given
        $user = $this->savedUser();
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/shopping-list/create');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertTrue(static::getContainer()->get(ShoppingListRepository::class)->hasActiveShoppingList($user->getId()));
    }

    public function testRefusesAListCreatedByGet(): void
    {
        // Given
        $user = $this->savedUser();
        $this->client->loginUser($user);

        // When : 'create' est alors lu comme l'id d'une liste (GET /shopping-list/{id}), inconnue
        $this->client->request('GET', '/shopping-list/create');

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertFalse(static::getContainer()->get(ShoppingListRepository::class)->hasActiveShoppingList($user->getId()));
    }

    public function testRedirectsAnAnonymousVisitorToTheLogin(): void
    {
        // When
        $this->client->request('POST', '/shopping-list/create');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }
}

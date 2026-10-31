<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingListType\GetShoppingListTypeQuery;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeIngredientDTO;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetShoppingListTypeApiControllerTest extends QueryBusWebTestCase
{
    private const string ID = '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34';

    public function testReturnsTheTypeWithItsDepartmentsAndItsProducts(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetShoppingListTypeQuery::class, new ShoppingListTypeDTO(
            self::ID,
            'Boucherie',
            [new ShoppingListTypeDepartmentDTO('viandes', 'Viandes')],
            [new ShoppingListTypeIngredientDTO('merguez', 'Merguez', 'charcuterie', 'Charcuterie')],
        ));

        // When
        $this->client->request('GET', '/api/v1/shopping-list-types/' . self::ID, server: $this->bearerFor($user));

        // Then : le type vient du chemin et l'utilisateur du jeton
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListTypeQuery::class, $query);
        $this->assertSame([self::ID, $user->getId()], [$query->id, $query->userId]);
        $this->assertSame([
            'id' => self::ID,
            'name' => 'Boucherie',
            'departments' => [['slug' => 'viandes', 'name' => 'Viandes']],
            'ingredients' => [['slug' => 'merguez', 'name' => 'Merguez', 'departmentSlug' => 'charcuterie', 'departmentName' => 'Charcuterie']],
        ], $this->jsonBody());
    }

    public function testATypeOfAnotherUserOrAnUnknownOneIsNotFound(): void
    {
        // Given : le loader refuse
        $this->queryBus->willThrow(GetShoppingListTypeQuery::class, ShoppingListTypeException::notFound());

        // When
        $this->client->request('GET', '/api/v1/shopping-list-types/' . self::ID, server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'LIST_TYPE_NOT_FOUND']], $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-list-types/' . self::ID);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

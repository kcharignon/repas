<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\Builder\DTO\IngredientDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetIngredientApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheIngredient(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetIngredientQuery::class, new IngredientDTOBuilder()
            ->withSlug('beurre')->withName('beurre')->withDepartment('cremerie', 'crèmerie')
            ->withCookingUnit('gramme', 'gramme')->withPurchaseUnit('plaquette', 'plaquette', '')->withCoefficient(250)
            ->withCreatorId($user->getId())->editable()->withUsage(7, 2)->base()->settableBase()->build());

        // When
        $this->client->request('GET', '/api/v1/ingredients/beurre', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetIngredientQuery::class, $query);
        $this->assertSame(['beurre', $user->getId()], [$query->slug, $query->viewerId]);
        $this->assertSame([
            'slug' => 'beurre',
            'name' => 'beurre',
            'image' => '',
            'departmentSlug' => 'cremerie',
            'departmentName' => 'crèmerie',
            'cookingUnitSlug' => 'gramme',
            'cookingUnitName' => 'gramme',
            'purchaseUnitSlug' => 'plaquette',
            'purchaseUnitName' => 'plaquette',
            'purchaseUnitSymbol' => '',
            'hasSameUnit' => false,
            'coefficient' => 250,
            'creatorId' => $user->getId(),
            'canEdit' => true,
            'recipeCount' => 7,
            'listCount' => 2,
            'isBase' => true,
            'canSetBase' => true,
            'listDestination' => ['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null],
        ], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownIngredient(): void
    {
        // Given
        $this->queryBus->willThrow(GetIngredientQuery::class, IngredientException::notFound('inconnu'));

        // When
        $this->client->request('GET', '/api/v1/ingredients/inconnu', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/beurre');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

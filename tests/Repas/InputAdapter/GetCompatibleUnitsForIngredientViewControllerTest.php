<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQuery;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetCompatibleUnitsForIngredientViewControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheUnitsAsTheFormScriptExpectsThem(): void
    {
        // Given
        $this->queryBus->willReturn(ListCompatibleUnitsQuery::class, [new CompatibleUnitDTO('gramme', 'Gramme'), new CompatibleUnitDTO('kilo', 'Kilo')]);
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/ingredients/farine/units');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListCompatibleUnitsQuery::class, $query);
        $this->assertSame('farine', $query->ingredientSlug);
        $this->assertSame(
            ['units' => [['name' => 'Gramme', 'slug' => 'gramme'], ['name' => 'Kilo', 'slug' => 'kilo']]],
            $this->jsonBody(),
        );
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/ingredients/farine/units');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

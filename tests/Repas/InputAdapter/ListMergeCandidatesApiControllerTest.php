<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListMergeCandidates\ListMergeCandidatesQuery;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListMergeCandidatesApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItemsWithAllTheKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListMergeCandidatesQuery::class, [
            new MergeCandidateDTO('tomate', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo', 12, 3, true, true),
            new MergeCandidateDTO('tomate-surgelee', 'Tomate surgelée', 'surgele', 'Surgelé', 'sachet', 0, 1, false, false),
        ]);

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-candidates', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListMergeCandidatesQuery($user->getId(), 'tomates'), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'tomate', 'name' => 'Tomate', 'departmentSlug' => 'fruit-et-legume', 'departmentName' => 'Fruit & légume', 'purchaseUnitName' => 'kilo', 'recipeCount' => 12, 'listCount' => 3, 'sameDepartment' => true, 'veryClose' => true],
                ['slug' => 'tomate-surgelee', 'name' => 'Tomate surgelée', 'departmentSlug' => 'surgele', 'departmentName' => 'Surgelé', 'purchaseUnitName' => 'sachet', 'recipeCount' => 0, 'listCount' => 1, 'sameDepartment' => false, 'veryClose' => false],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testTheSearchIsTransmittedToTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListMergeCandidatesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-candidates?q=tom', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListMergeCandidatesQuery($user->getId(), 'tomates', 'tom'), $this->queryBus->getLastAskedQuery());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testASearchThatIsTooLongIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-candidates?q=' . str_repeat('a', 101), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testACommonIngredientIsRefusedWith422AndAnUnknownOneWith404(): void
    {
        foreach ([[IngredientException::cannotMergeCommon(), 422, 'CANNOT_MERGE_COMMON_INGREDIENT'], [IngredientException::notFound('tomates'), 404, "Ingredient 'tomates' not found"]] as [$failure, $status, $message]) {
            // Given
            $this->queryBus->willThrow(ListMergeCandidatesQuery::class, $failure);

            // When
            $this->client->request('GET', '/api/v1/ingredients/tomates/merge-candidates', server: $this->bearerFor($this->savedUser()));

            // Then
            $this->assertSame([$status, ['error' => ['message' => $message]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        }
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-candidates');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

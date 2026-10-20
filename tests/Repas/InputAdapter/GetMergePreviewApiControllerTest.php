<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetMergePreview\GetMergePreviewQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\Builder\DTO\MergePreviewDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetMergePreviewApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsThePreviewWithAllItsKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetMergePreviewQuery::class, new MergePreviewDTOBuilder()->withUsage(7, 2, 1)->build());

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview?into=tomate', server: $this->bearerFor($user));

        // Then : l'utilisateur vient du jeton, l'ingrédient de l'URL est l'absorbé, `into` le gardé
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetMergePreviewQuery($user->getId(), 'tomates', 'tomate'), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'keep' => ['slug' => 'tomate', 'name' => 'Tomate', 'departmentSlug' => 'fruit-et-legume', 'departmentName' => 'Fruit & légume', 'purchaseUnitName' => 'kilo'],
            'absorbed' => ['slug' => 'tomates', 'name' => 'Tomates', 'departmentSlug' => 'fruit-et-legume', 'departmentName' => 'Fruit & légume', 'purchaseUnitName' => 'kilo'],
            'recipeCount' => 7,
            'listCount' => 2,
            'driveChoiceCount' => 1,
            'departmentChanges' => false,
            'listQuantitiesConverted' => false,
            'missingUnits' => [],
            'blockedBy' => null,
            'canMerge' => true,
        ], $this->jsonBody());
    }

    public function testAMissingUnitComesBackInTheBodyNotAsAnError(): void
    {
        // Given
        $this->queryBus->willReturn(GetMergePreviewQuery::class, new MergePreviewDTOBuilder()->missing('tranche', 'tranche')->build());

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview?into=tomate', server: $this->bearerFor($this->savedUser()));

        // Then : 200 toujours, le front affiche la bonne phrase sans passer par une erreur
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[ 'slug' => 'tranche', 'name' => 'tranche']], $this->jsonBody()['missingUnits']);
        $this->assertSame([false, 'UNIT_MISSING'], [$this->jsonBody()['canMerge'], $this->jsonBody()['blockedBy']]);
    }

    public function testAnAdministratorAsksAboutHisOwnIngredientsLikeAnyoneElse(): void
    {
        // Given
        $admin = $this->savedUser(true);
        $this->queryBus->willReturn(GetMergePreviewQuery::class, new MergePreviewDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview?into=tomate', server: $this->bearerFor($admin));

        // Then : la lecture ne connaît que l'identifiant, pas le rôle
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetMergePreviewQuery($admin->getId(), 'tomates', 'tomate'), $this->queryBus->getLastAskedQuery());
    }

    public function testAnIngredientUnknownOrOfAnotherUserIs404(): void
    {
        // Given
        $this->queryBus->willThrow(GetMergePreviewQuery::class, IngredientException::notFound('tomates'));

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview?into=tomate', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testTheTargetIsRequired(): void
    {
        foreach (['', '?into=', '?into=' . str_repeat('a', 256)] as $query) {
            // When
            $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview' . $query, server: $this->bearerFor($this->savedUser()));

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge-preview?into=tomate');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

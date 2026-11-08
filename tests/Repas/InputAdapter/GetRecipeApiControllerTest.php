<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\DTO\RecipeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetRecipeApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheRecipeWithItsRows(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()
            ->withId($id)->withName('pâtes carbonara')->withServing(4)->withType('plat', 'plat')->withVersion(3)
            ->withRow(new RecipeRowDTO('pate', 'pâtes', 'images/pate.png', 500.0, 'gramme', 'g', 'gramme', 'kilo', 'kilo', false, 'epicerie', 'épicerie', null))
            ->build());

        // When
        $user = $this->savedUser();
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('"3"', $this->client->getResponse()->headers->get('ETag'));
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertSame($id, $query->recipeId);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'id' => $id,
            'name' => 'pâtes carbonara',
            'serving' => 4,
            'typeSlug' => 'plat',
            'typeName' => 'plat',
            'secret' => false,
            'favorite' => false,
            'rows' => [[
                'ingredientSlug' => 'pate',
                'ingredientName' => 'pâtes',
                'ingredientImage' => 'images/pate.png',
                'quantity' => 500,
                'unitSlug' => 'gramme',
                'unitSymbol' => 'g',
                'unitName' => 'gramme',
                'purchaseUnitSlug' => 'kilo',
                'purchaseUnitName' => 'kilo',
                'convertible' => false,
                'departmentSlug' => 'epicerie',
                'departmentName' => 'épicerie',
                'partOfName' => null,
            ]],
            'version' => 3,
            'sourceUrl' => null,
            'originalId' => null,
            'canEdit' => true,
        ], $this->jsonBody());
    }

    public function testTheOriginalAndTheRightToEditAreAlwaysWritten(): void
    {
        // Given
        $id = UuidGenerator::new();
        $original = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()->withId($id)->withOriginalId($original)->withoutEditRight()->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $body = $this->jsonBody();
        $this->assertSame([$original, false], [$body['originalId'], $body['canEdit']]);
    }

    public function testTheQueryTellsThatTheReaderIsAnAdministrator(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()->withId($id)->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertTrue($query->isAdministrator);
    }

    public function testTheQueryTellsThatTheReaderIsNotAnAdministrator(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()->withId($id)->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertFalse($query->isAdministrator);
    }

    public function testTheLinkOfOriginIsAlwaysWrittenAndRendersAsGiven(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()->withId($id)->withSourceUrl('https://www.marmiton.org/recettes/recette_tarte_22082.aspx')->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame('https://www.marmiton.org/recettes/recette_tarte_22082.aspx', $this->jsonBody()['sourceUrl']);
    }

    public function testTheSecretFlagIsAlwaysWrittenAndTrueForASecretRecipe(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()->withId($id)->asSecret()->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->jsonBody()['secret']);
    }

    public function testReturns404ForAnUnknownRecipe(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willThrow(GetRecipeQuery::class, RecipeException::notFound($id));

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/' . UuidGenerator::new());

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

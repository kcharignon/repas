<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Query\ListUsableRecipes\ListUsableRecipesQuery;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipeDTO;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipesDTO;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListUsableRecipesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheUsableRecipesWithTheirKeysAndAskedForTheCurrentUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListUsableRecipesQuery::class, new UsableRecipesDTO([new UsableRecipeDTO('dough', 'Pâte sablée', 6, 'dessert', 'Dessert', false)], 1, true));

        // When : sans paramètre, la recette qu'on crée
        $this->client->request('GET', '/api/v1/recipes/usable', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListUsableRecipesQuery::class, $query);
        $this->assertSame([$user->getId(), null, false, null], [$query->userId, $query->forRecipeId, $query->secret, $query->search]);
        $this->assertSame([
            'member' => [['id' => 'dough', 'name' => 'Pâte sablée', 'serving' => 6, 'typeSlug' => 'dessert', 'typeName' => 'Dessert', 'secret' => false]],
            'totalItems' => 1,
            'canHaveComponents' => true,
        ], $this->jsonBody());
    }

    /**
     * @return iterable<string, array{string, bool|null, string|null}> la requête, le choix `secret`, le texte cherché
     */
    public static function parameters(): iterable
    {
        yield 'secrète' => ['secret=true', true, null];
        yield 'secrète, écrit 1' => ['secret=1', true, null];
        yield 'ordinaire, écrit false' => ['secret=false', false, null];
        yield 'avec un texte' => ['q=' . 'pate+sablee', false, 'pate sablee'];
    }

    #[DataProvider('parameters')]
    public function testForwardsTheSecretChoiceAndTheSearch(string $queryString, bool $secret, ?string $search): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListUsableRecipesQuery::class, new UsableRecipesDTO([], 0, true));

        // When
        $this->client->request('GET', '/api/v1/recipes/usable?' . $queryString, server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListUsableRecipesQuery::class, $query);
        $this->assertSame([$secret, $search], [$query->secret, $query->search]);
    }

    public function testTheRecipeBeingEditedIsForwardedWhenTheUserMayEditIt(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);
        $this->queryBus->willReturn(ListUsableRecipesQuery::class, new UsableRecipesDTO([], 0, false));

        // When
        $this->client->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $recipe->getId(), server: $this->bearerFor($author));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListUsableRecipesQuery::class, $query);
        $this->assertSame($recipe->getId(), $query->forRecipeId);
        $this->assertFalse($this->jsonBody()['canHaveComponents']);
    }

    public function testTheRecipeOfAnotherUserIsForbiddenAndAnUnknownOneIsNotFoundAndNothingIsAsked(): void
    {
        // Given
        $other = new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($other);
        $user = $this->savedUser();

        // When & Then : comme `PUT /recipes/{id}`
        $this->client->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $other->getId(), server: $this->bearerFor($user));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/recipes/usable?forRecipeId=00000000-0000-4000-8000-000000000001', server: $this->bearerFor($user));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnAdministratorMayAskForTheRecipeOfAnotherButNotForItsSecretOne(): void
    {
        // Given
        $author = $this->savedUser();
        $ordinary = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $secret = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->asSecret()->build();
        $this->persister->saveRecipe($ordinary);
        $this->persister->saveRecipe($secret);
        $admin = $this->savedUser(admin: true);
        $this->queryBus->willReturn(ListUsableRecipesQuery::class, new UsableRecipesDTO([], 0, true));

        // When & Then
        $this->client->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $ordinary->getId(), server: $this->bearerFor($admin));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $secret->getId(), server: $this->bearerFor($admin));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'recette trop longue' => ['forRecipeId=' . str_repeat('a', 37)];
        yield 'texte trop long' => ['q=' . str_repeat('a', 101)];
        yield 'secret qui n\'est pas un booléen' => ['secret=peut-etre'];
    }

    #[DataProvider('invalidQueries')]
    public function testRefusesInvalidParametersWithoutAsking(string $queryString): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/usable?' . $queryString, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/usable');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}

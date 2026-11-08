<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateRecipeApiControllerTest extends QueryBusWebTestCase
{
    private const array BODY = [
        'name' => 'Pâtes revisitées',
        'serving' => 6,
        'typeSlug' => 'plat',
        'rows' => [['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 750]],
    ];

    public function testDispatchesTheReplacementAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateRecipeCommand(
            $recipe->getId(),
            'Pâtes revisitées',
            6,
            new Tab([new UpdateRecipeRowSubCommand('pate', 'gramme', 750)], UpdateRecipeRowSubCommand::class),
            'plat',
            userId: $author->getId(),
        )], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool|null}>
     */
    public static function secretChoices(): iterable
    {
        yield 'absent (ancien client)' => [[], null];
        yield '`null`' => [['secret' => null], null];
        yield 'vrai' => [['secret' => true], true];
        yield 'faux' => [['secret' => false], false];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('secretChoices')]
    public function testForwardsTheSecretChoiceAndTheUserToTheCommandAndAnAbsentOneStaysNull(array $extra, ?bool $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, $extra + self::BODY);

        // Then : « inchangé » est `null`, jamais « faux » ; l'utilisateur est celui du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[$expected, $author->getId()]], array_map(static fn(UpdateRecipeCommand $command): array => [$command->secret, $command->userId], $commandBus->dispatched()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notBooleans(): iterable
    {
        yield 'texte' => ['oui'];
        yield 'texte true' => ['true'];
        yield 'entier 1' => [1];
        yield 'entier 0' => [0];
        yield 'liste' => [[]];
        yield 'objet' => [['secret' => true]];
        yield 'décimal' => [1.5];
    }

    #[DataProvider('notBooleans')]
    public function testRefusesASecretWhichIsNotABooleanWithoutDispatching(mixed $secret): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['secret' => $secret] + self::BODY);

        // Then : aucune conversion silencieuse
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesARecipeOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $recipe = $this->savedRecipe($this->savedUser());

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAdministratorEditsARecipeWithoutAnOwnerAndTheCommandSaysSo(): void
    {
        // Given : la recette Marmiton neutre (sans propriétaire)
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(admin: true);
        $neutral = $this->savedNeutral();

        // When
        $this->put('/api/v1/recipes/' . $neutral->getId(), $admin, self::BODY);

        // Then : seul l'administrateur la modifie, la commande porte son identifiant et le dit
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateRecipeCommand(
            $neutral->getId(),
            'Pâtes revisitées',
            6,
            new Tab([new UpdateRecipeRowSubCommand('pate', 'gramme', 750)], UpdateRecipeRowSubCommand::class),
            'plat',
            userId: $admin->getId(),
            byAdministrator: true,
        )], $commandBus->dispatched());
    }

    public function testAnOrdinaryUserCannotEditARecipeWithoutAnOwner(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $neutral = $this->savedNeutral();

        // When
        $this->put('/api/v1/recipes/' . $neutral->getId(), $this->savedUser(), self::BODY);

        // Then : `403`, rien n'est envoyé
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAdministratorEditingTheRecipeOfAnotherUserIsMarkedAsAnAdministratorToo(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(admin: true);
        $recipe = $this->savedRecipe($this->savedUser());

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $admin, self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($commandBus->dispatched()[0]->byAdministrator);
    }

    public function testTheSecretRecipeOfAnotherIsUnknownEvenToAnAdministratorWhoNeverSeesItsAuthorsChoice(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser())->asSecret()->build();
        $this->persister->saveRecipe($recipe);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $this->savedUser(admin: true), self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownRecipe(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('/api/v1/recipes/unknown-recipe', $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnEmptyListOfRowsReachesTheUseCaseWhichJudgesTheFinalState(): void
    {
        // Given : une recette peut être faite seulement de recettes utilisées (ADR 0040 décision 9) : « lignes + composants ≥ 1 » est jugé par le cas
        // d'usage (`422 RECIPE_EMPTY`, voir `RecipeComponentsApiFlowTest`), plus par le corps de la requête
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['rows' => []] + self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $this->assertTrue($commandBus->dispatched()[0]->rows->empty());
    }

    public function testRefusesMoreThanAHundredRows(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['rows' => array_fill(0, 101, self::BODY['rows'][0])] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{string, float}>|null}> le corps en plus, les composants de la commande
     */
    public static function componentsBodies(): iterable
    {
        yield 'absent (ancien client) : inchangé' => [[], null];
        yield '`null` : inchangé' => [['components' => null], null];
        yield '`[]` : tout retirer' => [['components' => []], []];
        yield 'deux recettes, dans l\'ordre' => [['components' => [['recipeId' => 'b-recipe', 'multiplier' => 0.5], ['recipeId' => 'a-recipe', 'multiplier' => 2]]], [['b-recipe', 0.5], ['a-recipe', 2.0]]];
        yield 'un multiplicateur entier JSON' => [['components' => [['recipeId' => 'a-recipe', 'multiplier' => 3]]], [['a-recipe', 3.0]]];
        yield 'les bornes' => [['components' => [['recipeId' => 'a-recipe', 'multiplier' => 0.01], ['recipeId' => 'b-recipe', 'multiplier' => 100]]], [['a-recipe', 0.01], ['b-recipe', 100.0]]];
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<array{string, float}>|null $expected
     */
    #[DataProvider('componentsBodies')]
    public function testForwardsTheComponentsToTheCommandAndAnAbsentOneStaysNullNeverEmpty(array $extra, ?array $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, $extra + self::BODY);

        // Then : « inchangé » est `null`, jamais `[]` (qui retire tout)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $components = $commandBus->dispatched()[0]->components;
        $this->assertSame($expected, $components === null ? null : array_map(static fn(RecipeComponentSubCommand $component): array => [$component->recipeId, $component->multiplier], array_values($components->toArray())));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidComponents(): iterable
    {
        yield 'un texte' => ['beaucoup'];
        yield 'un nombre' => [3];
        yield 'un élément qui n\'est pas un objet' => [[1]];
        yield 'sans identifiant' => [[['multiplier' => 1]]];
        yield 'identifiant vide' => [[['recipeId' => '', 'multiplier' => 1]]];
        yield 'identifiant trop long' => [[['recipeId' => str_repeat('a', 37), 'multiplier' => 1]]];
        yield 'identifiant qui n\'est pas un texte' => [[['recipeId' => 12, 'multiplier' => 1]]];
        yield 'sans multiplicateur' => [[['recipeId' => 'a-recipe']]];
        yield 'multiplicateur nul (`null`)' => [[['recipeId' => 'a-recipe', 'multiplier' => null]]];
        yield 'multiplicateur texte' => [[['recipeId' => 'a-recipe', 'multiplier' => '2']]];
        yield 'multiplicateur booléen' => [[['recipeId' => 'a-recipe', 'multiplier' => true]]];
        yield 'multiplicateur liste' => [[['recipeId' => 'a-recipe', 'multiplier' => [1]]]];
        yield 'multiplicateur zéro' => [[['recipeId' => 'a-recipe', 'multiplier' => 0]]];
        yield 'multiplicateur négatif' => [[['recipeId' => 'a-recipe', 'multiplier' => -1]]];
        yield 'multiplicateur sous la borne' => [[['recipeId' => 'a-recipe', 'multiplier' => 0.0099]]];
        yield 'multiplicateur au-dessus de la borne' => [[['recipeId' => 'a-recipe', 'multiplier' => 100.01]]];
    }

    #[DataProvider('invalidComponents')]
    public function testRefusesComponentsWhichAreNotAListOfRecipesWithoutDispatching(mixed $components): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['components' => $components] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testMoreThanTenComponentsReachTheUseCaseWhichRefusesThemWithTheirCodeBeforeAnyRead(): void
    {
        // Given : le nombre est jugé par le cas d'usage (`RECIPE_COMPONENT_LIMIT_REACHED`), pas par le corps, pour que le message soit le code
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['components' => array_map(static fn(int $n): array => ['recipeId' => "recipe-$n", 'multiplier' => 1], range(1, 11))] + self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(11, $commandBus->dispatched()[0]->components);
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/recipes/any-recipe', content: json_encode(self::BODY), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * La recette Marmiton neutre : son auteur est l'identifiant réservé, qui n'est le compte de personne.
     */
    private function savedNeutral(): \Repas\Repas\Domain\Model\Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->asMarmitonNeutral()->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedRecipe(User $author): \Repas\Repas\Domain\Model\Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $uri, User $user, array $body): void
    {
        $this->client->request('PUT', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->client->request('PUT', '/api/v1/recipes/' . $recipe->getId(), content: json_encode(self::BODY, JSON_THROW_ON_ERROR), server: $this->bearerFor($author) + ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => '"4"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(4, $commandBus->dispatched()[0]->expectedVersion);
    }

    public function testRefusesAnIfMatchWhichIsNotAnEtagWithoutDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->client->request('PUT', '/api/v1/recipes/' . $recipe->getId(), content: json_encode(self::BODY, JSON_THROW_ON_ERROR), server: $this->bearerFor($author) + ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => 'abc']);

        // Then
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testRefusesAnEmptyListOfRows(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['rows' => []] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
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

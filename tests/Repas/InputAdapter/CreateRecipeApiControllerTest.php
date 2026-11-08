<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateRecipeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheCurrentUserAndReturnsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();

        // When
        $this->post($author, [
            'name' => 'Pâtes carbonara',
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 500],
                ['ingredientSlug' => 'oeuf', 'unitSlug' => 'unite', 'quantity' => 2.5],
            ],
        ]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(CreateRecipeCommand::class, $command);
        $this->assertSame(['Pâtes carbonara', 4, $author->getId(), 'plat'], [$command->name, $command->serving, $command->authorId, $command->typeSlug]);
        $this->assertEquals(new Tab([
            new CreateRecipeRowSubCommand('pate', 'gramme', 500),
            new CreateRecipeRowSubCommand('oeuf', 'unite', 2.5),
        ], CreateRecipeRowSubCommand::class), $command->rows);
        $this->assertSame('/api/v1/recipes/' . $command->id, $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $row = ['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 500];
        $valid = ['name' => 'Pâtes', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [$row]];

        yield 'sans nom' => [['name' => ''] + $valid];
        yield 'nom trop long' => [['name' => str_repeat('a', 256)] + $valid];
        yield 'zéro personne' => [['serving' => 0] + $valid];
        yield 'trop de personnes' => [['serving' => 101] + $valid];
        yield 'sans type' => [['typeSlug' => ''] + $valid];
        yield 'plus de cent lignes' => [['rows' => array_fill(0, 101, $row)] + $valid];
        yield 'quantité nulle' => [['rows' => [['quantity' => 0] + $row]] + $valid];
        yield 'quantité de mauvais type' => [['rows' => [['quantity' => 'beaucoup'] + $row]] + $valid];
        yield 'ligne sans ingrédient' => [['rows' => [['ingredientSlug' => ''] + $row]] + $valid];
        yield 'ligne sans unité' => [['rows' => [['unitSlug' => ''] + $row]] + $valid];
    }

    public function testAnEmptyListOfRowsReachesTheUseCaseWhichJudgesTheFinalStateAndComponentsAreForwarded(): void
    {
        // Given : une recette peut être faite seulement de recettes utilisées (ADR 0040 décision 9) : « lignes + composants ≥ 1 » est jugé par le cas
        // d'usage (`422 RECIPE_EMPTY`, voir `RecipeComponentsApiFlowTest`)
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();

        // When
        $this->post($author, ['name' => 'Tarte', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [], 'components' => [['recipeId' => 'dough', 'multiplier' => 2], ['recipeId' => 'cream', 'multiplier' => 0.5]]]);

        // Then : les composants, dans l'ordre reçu
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $command = $commandBus->dispatched()[0];
        $this->assertTrue($command->rows->empty());
        $this->assertEquals(new Tab([new RecipeComponentSubCommand('dough', 2.0), new RecipeComponentSubCommand('cream', 0.5)], RecipeComponentSubCommand::class), $command->components);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int|null}> le corps en plus, le nombre de composants de la commande
     */
    public static function absentOrEmptyComponents(): iterable
    {
        yield 'absent (ancien client)' => [[], null];
        yield '`null`' => [['components' => null], null];
        yield '`[]`' => [['components' => []], 0];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('absentOrEmptyComponents')]
    public function testComponentsAbsentOrNullStayNullInTheCommandAndAnEmptyListStaysEmpty(array $extra, ?int $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), $extra + ['name' => 'Pâtes', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 500]]]);

        // Then : `null` = aucun à la création, jamais confondu avec `[]`
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame($expected, $commandBus->dispatched()[0]->components?->count());
    }

    /**
     * @param array<string, mixed> $component
     */
    #[DataProvider('invalidComponents')]
    public function testRefusesComponentsWhichAreNotAListOfRecipes(array $component): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['name' => 'Tarte', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 500]], 'components' => [$component]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidComponents(): iterable
    {
        yield 'sans identifiant' => [['multiplier' => 1]];
        yield 'identifiant vide' => [['recipeId' => '', 'multiplier' => 1]];
        yield 'sans multiplicateur' => [['recipeId' => 'dough']];
        yield 'multiplicateur texte' => [['recipeId' => 'dough', 'multiplier' => '2']];
        yield 'multiplicateur nul' => [['recipeId' => 'dough', 'multiplier' => 0]];
        yield 'multiplicateur trop grand' => [['recipeId' => 'dough', 'multiplier' => 101]];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/recipes', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/recipes', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}

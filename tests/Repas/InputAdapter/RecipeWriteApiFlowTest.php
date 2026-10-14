<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des endpoints d'écriture des recettes avec les vrais bus, la vraie base et les listeners (voir
 * `ShoppingListWriteApiFlowTest`).
 */
class RecipeWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCreatesARecipeWithItsRowsAndUpdatesTheStatisticsOfItsAuthor(): void
    {
        // Given
        $author = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());

        // When
        $this->request('POST', '/api/v1/recipes', $author, $this->body($pasta->getSlug()));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $recipe = $this->recipes()->findOneById(basename($this->client->getResponse()->headers->get('Location')));
        $this->assertSame(['Pâtes carbonara', 4, $author->getId()], [$recipe->getName(), $recipe->getServing(), $recipe->getAuthorId()]);
        $this->assertSame([[$pasta->getSlug(), 'gramme', 500.0]], array_map(fn(RecipeRow $row) => [$row->getIngredient()->getSlug(), $row->getUnit()->getSlug(), $row->getQuantity()], array_values($recipe->getRows()->toArray())));
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->findOneById($author->getId())->getStatistics()['recipes']);
    }

    public function testAnIngredientCustomisedByAnotherUserIsUnknown(): void
    {
        // Given
        $author = $this->savedUser();
        $private = new IngredientBuilder()->isPasta()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($private);
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());

        // When
        $this->request('POST', '/api/v1/recipes', $author, $this->body($private->getSlug()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => IngredientException::notFound($private->getSlug())->getMessage()]], $this->jsonBody());
    }

    public function testAnUnknownRecipeTypeIsNotFound(): void
    {
        // Given
        $author = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);

        // When
        $this->request('POST', '/api/v1/recipes', $author, ['typeSlug' => 'inconnu'] + $this->body($pasta->getSlug()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => RecipeException::typeNotFound('inconnu')->getMessage()]], $this->jsonBody());
    }

    public function testReplacesTheRowsOfARecipe(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);
        $butter = new IngredientBuilder()->isButter()->build();
        $this->persister->saveIngredient($butter);

        // When
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId(), $author, [
            'name' => 'Beurre seul',
            'serving' => 2,
            'typeSlug' => $recipe->getType()->getSlug(),
            'rows' => [['ingredientSlug' => $butter->getSlug(), 'unitSlug' => $butter->getDefaultPurchaseUnit()->getSlug(), 'quantity' => 1]],
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $updated = $this->recipes()->findOneById($recipe->getId());
        $this->assertSame(['Beurre seul', 2, [$butter->getSlug()]], [$updated->getName(), $updated->getServing(), array_map(fn(RecipeRow $row) => $row->getIngredient()->getSlug(), array_values($updated->getRows()->toArray()))]);
        $this->assertSame(1, $this->rowCount($recipe->getId()));
    }

    public function testTheRowsKeepTheOrderOfTheBodyAndAReplacementReordersThem(): void
    {
        // Given : trois ingrédients dont l'ordre alphabétique n'est pas celui de la saisie
        $author = $this->savedUser();
        $butter = new IngredientBuilder()->isButter()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        foreach ([$butter, $pasta, $egg] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $row = static fn($ingredient): array => ['ingredientSlug' => $ingredient->getSlug(), 'unitSlug' => $ingredient->getDefaultPurchaseUnit()->getSlug(), 'quantity' => 2];
        $slugsOfTheRecipe = fn(string $id): array => array_map(fn($r) => $r['ingredientSlug'], (function () use ($id, $author) {
            $this->request('GET', '/api/v1/recipes/' . $id, $author);

            return $this->jsonBody()['rows'];
        })());

        // When : création
        $this->request('POST', '/api/v1/recipes', $author, ['name' => 'Trio', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [$row($butter), $row($pasta), $row($egg)]]);
        $id = basename($this->client->getResponse()->headers->get('Location'));

        // Then : relue dans l'ordre de la saisie
        $this->assertSame([$butter->getSlug(), $pasta->getSlug(), $egg->getSlug()], $slugsOfTheRecipe($id));

        // When : remplacement avec un autre ordre
        $this->request('PUT', '/api/v1/recipes/' . $id, $author, ['name' => 'Trio', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [$row($egg), $row($butter), $row($pasta)]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then
        $this->assertSame([$egg->getSlug(), $butter->getSlug(), $pasta->getSlug()], $slugsOfTheRecipe($id));
    }

    public function testAUnitWithoutAConversionToThePurchaseUnitIsRefused(): void
    {
        // Given : les pâtes s'achètent au gramme, rien ne convertit l'unité en gramme
        $author = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveUnit(new UnitBuilder()->isUnite()->build());

        // When
        $this->request('POST', '/api/v1/recipes', $author, ['name' => 'Pâtes', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => $pasta->getSlug(), 'unitSlug' => 'unite', 'quantity' => 3]]]);

        // Then : 422 à l'enregistrement, rien n'est créé
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(0, $this->recipes()->findByAuthor($author->getId())->count());
    }

    public function testAnotherUserCannotUpdateOrDeleteTheRecipe(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->savedUser());
        $intruder = $this->savedUser();

        // When & Then
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId(), $intruder, $this->body('pate'));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipes/' . $recipe->getId(), $intruder);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame($recipe->getName(), $this->recipes()->findOneById($recipe->getId())->getName());
    }

    public function testCopiesARecipeIntoThoseOfTheCurrentUser(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->savedUser());
        $copier = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/recipes/' . $recipe->getId() . '/copy', $copier);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copy = $this->recipes()->findOneById(basename($this->client->getResponse()->headers->get('Location')));
        $this->assertSame([$copier->getId(), $recipe->getId(), $recipe->getName()], [$copy->getAuthorId(), $copy->getOriginalId(), $copy->getName()]);
        $this->assertSame($recipe->getAuthorId(), $this->recipes()->findOneById($recipe->getId())->getAuthorId());
    }

    public function testCopyingAnUnknownRecipeIsNotFound(): void
    {
        // When
        $this->request('POST', '/api/v1/recipes/unknown-recipe/copy', $this->savedUser());

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletesARecipeWithItsRows(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);
        $this->assertGreaterThan(0, $this->rowCount($recipe->getId()));

        // When
        $this->request('DELETE', '/api/v1/recipes/' . $recipe->getId(), $author);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount($recipe->getId()));
        $this->expectExceptionObject(RecipeException::notFound($recipe->getId()));
        $this->recipes()->findOneById($recipe->getId());
    }

    public function testARecipeInAShoppingListCannotBeDeleted(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);
        static::getContainer()->get(ShoppingListRepository::class)->save(new ShoppingListBuilder()->withOwner($author)->addRecipe($recipe)->build());

        // When
        $this->request('DELETE', '/api/v1/recipes/' . $recipe->getId(), $author);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame($recipe->getId(), $this->recipes()->findOneById($recipe->getId())->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $ingredientSlug): array
    {
        return [
            'name' => 'Pâtes carbonara',
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [['ingredientSlug' => $ingredientSlug, 'unitSlug' => 'gramme', 'quantity' => 500]],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function savedRecipe(User $author): Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function rowCount(string $recipeId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(rr.id)')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function recipes(): RecipeRepository
    {
        $this->entityManager->clear();

        return static::getContainer()->get(RecipeRepository::class);
    }
}

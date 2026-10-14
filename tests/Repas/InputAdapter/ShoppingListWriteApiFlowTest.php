<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des endpoints d'écriture des listes de courses avec les vrais bus, la vraie base et les listeners : le jeton
 * JWT désigne l'utilisateur, le handler s'exécute, l'effet se lit dans la base. Les tests unitaires des controllers
 * (`*ApiControllerTest`) doublent le bus de commandes et prouvent la commande construite ; celui-ci prouve le câblage.
 */
class ShoppingListWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCreatesAnActiveListAndReturnsItsLocation(): void
    {
        // Given
        $owner = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/shopping-lists', $owner);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#^/api/v1/shopping-lists/[0-9a-f-]{36}$#', $location);
        $created = $this->shoppingLists()->findOneById(basename($location));
        $this->assertSame([$owner->getId(), ShoppingListStatus::ACTIVE], [$created->getOwner()->getId(), $created->getStatus()]);
    }

    public function testAnActiveListIsCreatedOnlyWhenNoneExists(): void
    {
        // Given
        $owner = $this->savedUser();
        $active = $this->savedList($owner);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($active->getId(), $this->shoppingLists()->findOneActivateByOwner($owner)->getId());
    }

    public function testPlanningARecipeWithoutAnActiveListIsNotFound(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $owner);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => ShoppingListException::activeShoppingListNotFound()->getMessage()]], $this->jsonBody());
    }

    public function testPlanningARecipeAlreadyInTheListIsAConflict(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner);
        $this->savedList($owner, $recipe);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $owner);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testTickingTheLastLineCompletesTheList(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($egg)->build();
        $this->shoppingLists()->save($shoppingList);
        $rowId = $shoppingList->getRows()->reset()->getId();

        // When
        $this->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/tick', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertTrue(static::getContainer()->get(ShoppingListRowRepository::class)->findOneById($rowId)->isChecked());
        $this->assertSame(ShoppingListStatus::COMPLETED, $this->shoppingLists()->findOneById($shoppingList->getId())->getStatus());
    }

    public function testAnotherUserCannotTickTheLine(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($egg)->build();
        $this->shoppingLists()->save($shoppingList);
        $rowId = $shoppingList->getRows()->reset()->getId();

        // When
        $this->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/tick', $this->savedUser());

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertFalse(static::getContainer()->get(ShoppingListRowRepository::class)->findOneById($rowId)->isChecked());
    }

    public function testRenamesThenPausesThenDeletesAList(): void
    {
        // Given
        $owner = $this->savedUser();
        $shoppingList = $this->savedList($owner);
        $id = $shoppingList->getId();

        // When & Then : le client de test redémarre le noyau entre deux requêtes, la base reste la même
        $this->request('PATCH', "/api/v1/shopping-lists/$id", $owner, ['name' => 'Courses du week-end']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Courses du week-end', $this->shoppingLists()->findOneById($id)->getName());

        $this->request('POST', "/api/v1/shopping-lists/$id/pause", $owner);
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingLists()->findOneById($id)->getStatus());

        $this->request('DELETE', "/api/v1/shopping-lists/$id", $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound($id));
        $this->shoppingLists()->findOneById($id);
    }

    public function testChangesTheServingOfAMeal(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner);
        $shoppingList = $this->savedList($owner, $recipe);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->request('PUT', "/api/v1/meals/$mealId/serving", $owner, ['serving' => 9]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(9, $this->shoppingLists()->findOneById($shoppingList->getId())->getMeals()->reset()->getServing());
    }

    public function testActivatingAListPausesTheCurrentActiveOne(): void
    {
        // Given
        $owner = $this->savedUser();
        $active = $this->savedList($owner);
        $paused = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::PAUSED)->build();
        $this->shoppingLists()->save($paused);

        // When
        $this->request('POST', '/api/v1/shopping-lists/' . $paused->getId() . '/activate', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($paused->getId())->getStatus());
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingLists()->findOneById($active->getId())->getStatus());
    }

    public function testCompletingAListRecordsItInTheOwnerStatistics(): void
    {
        // Given
        $owner = $this->savedUser();
        $shoppingList = $this->savedList($owner);

        // When
        $this->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/complete', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::COMPLETED, $this->shoppingLists()->findOneById($shoppingList->getId())->getStatus());
        $statistics = static::getContainer()->get(UserRepository::class)->findOneById($owner->getId())->getShoppingListStats();
        $this->assertSame([$shoppingList->getId()], array_keys($statistics));
    }

    public function testUncheckingALineOfACompletedListReactivatesIt(): void
    {
        // Given : cocher la dernière ligne termine la liste
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($egg)->build();
        $this->shoppingLists()->save($shoppingList);
        $rowId = $shoppingList->getRows()->reset()->getId();
        $this->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/tick', $owner);
        $this->assertSame(ShoppingListStatus::COMPLETED, $this->shoppingLists()->findOneById($shoppingList->getId())->getStatus());

        // When
        $this->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/uncheck', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertFalse(static::getContainer()->get(ShoppingListRowRepository::class)->findOneById($rowId)->isChecked());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($shoppingList->getId())->getStatus());
    }

    public function testIncreasesThenDecreasesAnIngredientOfTheActiveList(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = $this->savedList($owner);

        // When & Then
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $egg->getSlug() . '/increase', $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $egg->getSlug() . '/increase', $owner);
        $this->assertSame(2.0, $this->shoppingLists()->findOneById($shoppingList->getId())->getIngredientQuantity($egg));

        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $egg->getSlug() . '/decrease', $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1.0, $this->shoppingLists()->findOneById($shoppingList->getId())->getIngredientQuantity($egg));
    }

    public function testUnplanningARecipeRemovesItsMeal(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($owner);
        $shoppingList = $this->savedList($owner, $recipe);

        // When
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->shoppingLists()->findOneById($shoppingList->getId())->getMeals()->count());
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
        $recipe = new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedList(User $owner, ?Recipe $recipe = null): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        if ($recipe !== null) {
            $builder->addRecipe($recipe);
        }
        $shoppingList = $builder->build();
        $this->shoppingLists()->save($shoppingList);

        return $shoppingList;
    }

    private function shoppingLists(): ShoppingListRepository
    {
        return static::getContainer()->get(ShoppingListRepository::class);
    }
}

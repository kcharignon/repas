<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
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
        $this->assertSame([$owner->getId(), ShoppingListStatus::ACTIVE], [$created->getOwnerId(), $created->getStatus()]);
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
        $this->assertSame($active->getId(), $this->shoppingLists()->findOneActivateByOwner($owner->getId())->getId());
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

    public function testPlanningTheRecipeOfAnotherUserIsNotFoundAndLeavesTheListUntouched(): void
    {
        // Given : la recette d'un autre utilisateur, avec ses ingrédients, et une liste active
        $owner = $this->savedUser();
        $recipe = $this->savedRecipe($this->savedUser());
        $list = $this->savedList($owner);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $owner);

        // Then : même réponse qu'une recette inconnue, et la liste n'a ni repas ni ligne
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => RecipeException::notFound($recipe->getId())->getMessage()]], $this->jsonBody());
        $reloaded = $this->shoppingLists()->findOneById($list->getId());
        $this->assertCount(0, $reloaded->getMeals());
        $this->assertCount(0, $reloaded->getRows());
    }

    public function testAnAdministratorCannotPlanTheRecipeOfAnotherUserEither(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $recipe = $this->savedRecipe($this->savedUser());
        $this->savedList($admin);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $admin);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
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

    public function testAddingTheCustomIngredientOfAnotherUserIsNotFoundAndLeavesTheListUntouched(): void
    {
        // Given : un ingrédient personnalisé d'un autre utilisateur, et une liste active
        $owner = $this->savedUser();
        $butter = new IngredientBuilder()->isButter()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($butter);
        $list = $this->savedList($owner);

        // When
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $butter->getSlug() . '/increase', $owner);

        // Then : même réponse qu'un ingrédient inconnu, et la liste n'a ni ligne ni ingrédient
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => IngredientException::notFound($butter->getSlug())->getMessage()]], $this->jsonBody());
        $reloaded = $this->shoppingLists()->findOneById($list->getId());
        $this->assertCount(0, $reloaded->getRows());
        $this->assertCount(0, $reloaded->getIngredients());
    }

    public function testAnAdministratorCannotAddTheCustomIngredientOfAnotherUserEither(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $butter = new IngredientBuilder()->isButter()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($butter);
        $this->savedList($admin);

        // When
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $butter->getSlug() . '/increase', $admin);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testACommonIngredientCanBeAddedByAnyUser(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $list = $this->savedList($owner);

        // When
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $egg->getSlug() . '/increase', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $this->shoppingLists()->findOneById($list->getId())->getRows());
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

    public function testIfMatchProtectsAListFromAStaleWrite(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = $this->savedList($owner);
        $increase = '/api/v1/shopping-lists/active/ingredients/' . $egg->getSlug() . '/increase';
        $etag = function () use ($owner, $shoppingList): string {
            $this->request('GET', '/api/v1/shopping-lists/' . $shoppingList->getId(), $owner);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());

            return $this->client->getResponse()->headers->get('ETag');
        };
        $firstEtag = $etag();

        // When : écriture avec la version lue
        $this->request('POST', $increase, $owner, headers: ['HTTP_IF_MATCH' => $firstEtag]);

        // Then : la version a changé, la lecture suivante le montre
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $secondEtag = $etag();
        $this->assertNotSame($firstEtag, $secondEtag);
        $this->assertSame($secondEtag, '"' . $this->jsonBody()['version'] . '"');

        // When : un second onglet rejoue l'ancienne version
        $this->request('POST', $increase, $owner, headers: ['HTTP_IF_MATCH' => $firstEtag]);

        // Then : refus, la quantité n'a pas bougé
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1.0, $this->shoppingLists()->findOneById($shoppingList->getId())->getIngredientQuantity($egg));

        // When : la version à jour passe, et l'absence d'en-tête aussi
        $this->request('POST', $increase, $owner, headers: ['HTTP_IF_MATCH' => $secondEtag]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', $increase, $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(3.0, $this->shoppingLists()->findOneById($shoppingList->getId())->getIngredientQuantity($egg));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers en-têtes en plus, au format $_SERVER (`HTTP_IF_MATCH`)
     */
    private function request(string $method, string $uri, User $user, ?array $body = null, array $headers = []): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ] + $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
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

    /**
     * Liste active d'un repas et d'une ligne (l'ingrédient de la recette) : elle peut être envoyée.
     */
    private function savedSendableList(User $owner, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): ShoppingList
    {
        $recipe = $this->savedRecipe($owner);
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($owner)
            ->withStatus($status)
            ->addRecipe($recipe)
            ->addIngredient($recipe->getRows()->reset()->getIngredient())
            ->build();
        $this->shoppingLists()->save($shoppingList);

        return $shoppingList;
    }

    private function shoppingLists(): ShoppingListRepository
    {
        return static::getContainer()->get(ShoppingListRepository::class);
    }

    public function testASentListIsLockedUntilItIsReopened(): void
    {
        // Given : une liste active d'un repas et d'une ligne
        $owner = $this->savedUser();
        $list = $this->savedSendableList($owner);
        $id = $list->getId();
        $mealId = $list->getMeals()->reset()->getId();
        $rowId = $list->getRows()->reset()->getId();
        $etag = function () use ($owner, $id): string {
            $this->request('GET', "/api/v1/shopping-lists/$id", $owner);

            return $this->client->getResponse()->headers->get('ETag');
        };
        $firstEtag = $etag();
        $this->assertSame([false, null], [$this->jsonBody()['isSent'], $this->jsonBody()['sentAt']]);

        // When : l'envoi avec la version lue
        $this->request('POST', "/api/v1/shopping-lists/$id/send", $owner, headers: ['HTTP_IF_MATCH' => $firstEtag]);

        // Then : la liste est envoyée, sa version a augmenté
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $sentEtag = $etag();
        $this->assertNotSame($firstEtag, $sentEtag);
        $sent = $this->jsonBody();
        $this->assertSame(['SENT', false, true], [$sent['status'], $sent['isActive'], $sent['isSent']]);
        $this->assertNotNull($sent['sentAt']);

        // When : un second envoi (même ancienne version) est refusé, un envoi sans en-tête n'a aucun effet
        $this->request('POST', "/api/v1/shopping-lists/$id/send", $owner, headers: ['HTTP_IF_MATCH' => $firstEtag]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/shopping-lists/$id/send", $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($sentEtag, $etag());

        // When & Then : aucune écriture de contenu ne passe, la liste ne bouge pas
        foreach ([
            ['PATCH', "/api/v1/shopping-lists/$id", ['name' => 'Autre nom']],
            ['PUT', "/api/v1/meals/$mealId/serving", ['serving' => 9]],
            ['POST', "/api/v1/shopping-list-rows/$rowId/tick", null],
            ['POST', "/api/v1/shopping-list-rows/$rowId/uncheck", null],
            ['POST', "/api/v1/shopping-lists/$id/pause", null],
            ['POST', "/api/v1/shopping-lists/$id/activate", null],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $owner, $body);
            $this->assertSame(409, $this->client->getResponse()->getStatusCode(), "$method $uri");
            $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_SENT']], $this->jsonBody(), "$method $uri");
        }
        $this->assertSame($sentEtag, $etag());
        $this->assertFalse(static::getContainer()->get(ShoppingListRowRepository::class)->findOneById($rowId)->isChecked());

        // When : la réouverture
        $this->request('POST', "/api/v1/shopping-lists/$id/reopen", $owner);

        // Then : la liste est active et modifiable de nouveau
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $etag();
        $reopened = $this->jsonBody();
        $this->assertSame(['ACTIVE', true, false, null], [$reopened['status'], $reopened['isActive'], $reopened['isSent'], $reopened['sentAt']]);
        $this->request('PATCH', "/api/v1/shopping-lists/$id", $owner, ['name' => 'Autre nom']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testASentListCanBeCompleted(): void
    {
        // Given
        $owner = $this->savedUser();
        $list = $this->savedSendableList($owner);
        $this->request('POST', '/api/v1/shopping-lists/' . $list->getId() . '/send', $owner);

        // When
        $this->request('POST', '/api/v1/shopping-lists/' . $list->getId() . '/complete', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::COMPLETED, $this->shoppingLists()->findOneById($list->getId())->getStatus());
    }

    public function testACompletedSentListKeepsItsDateUntilAUncheckMakesItActiveAgain(): void
    {
        // Given : une liste envoyée puis terminée
        $owner = $this->savedUser();
        $list = $this->savedSendableList($owner);
        $id = $list->getId();
        $rowId = $list->getRows()->reset()->getId();
        $this->request('POST', "/api/v1/shopping-lists/$id/send", $owner);
        $this->request('POST', "/api/v1/shopping-lists/$id/complete", $owner);
        $this->request('GET', "/api/v1/shopping-lists/$id", $owner);
        $completed = $this->jsonBody();
        $this->assertSame(['COMPLETED', false], [$completed['status'], $completed['isSent']]);
        $this->assertNotNull($completed['sentAt']);

        // When : décocher une ligne la remet en liste active
        $this->request('POST', "/api/v1/shopping-list-rows/$rowId/uncheck", $owner);

        // Then : elle n'est plus envoyée, sa date d'envoi non plus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/shopping-lists/$id", $owner);
        $active = $this->jsonBody();
        $this->assertSame(['ACTIVE', false, null], [$active['status'], $active['isSent'], $active['sentAt']]);
    }

    public function testSendingRefusesAnEmptyListAnInactiveListAndAnotherUsersList(): void
    {
        // Given
        $owner = $this->savedUser();
        $empty = $this->savedList($owner);
        $paused = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::PAUSED)->build();
        $this->shoppingLists()->save($paused);

        // When & Then
        $this->request('POST', '/api/v1/shopping-lists/' . $empty->getId() . '/send', $owner);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_EMPTY']], $this->jsonBody());

        $this->request('POST', '/api/v1/shopping-lists/' . $paused->getId() . '/send', $owner);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_NOT_SENDABLE']], $this->jsonBody());

        $this->request('POST', '/api/v1/shopping-lists/' . $empty->getId() . '/send', $this->savedUser());
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/api/v1/shopping-lists/unknown-list/send', $owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($empty->getId())->getStatus());
    }

    /**
     * DRV-613 (constat C3) : l'interrupteur coupé après un envoi (retour arrière, coupure d'urgence) ne fige pas la liste. L'envoi,
     * lui, est refusé ; les autres écritures de la liste restent verrouillées tant qu'elle est envoyée.
     */
    public function testASentListCanBeReopenedWhenTheSwitchIsOffAndSendingIsRefused(): void
    {
        // Given : l'interrupteur est coupé, une liste est restée envoyée (retour arrière, coupure d'urgence)
        $owner = $this->savedUser();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $sent = $this->savedSendableList($owner, ShoppingListStatus::SENT);
        $otherOwner = $this->savedUser();
        $other = $this->savedSendableList($otherOwner);

        // When : l'envoi d'une autre liste, interrupteur coupé
        $this->request('POST', '/api/v1/shopping-lists/' . $other->getId() . '/send', $otherOwner);

        // Then
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When : la liste envoyée reste verrouillée (écriture refusée), puis rouverte
        $this->request('PATCH', '/api/v1/shopping-lists/' . $sent->getId(), $owner, ['name' => 'Autre nom']);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/' . $sent->getId() . '/reopen', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($sent->getId())->getStatus());
        $this->request('PATCH', '/api/v1/shopping-lists/' . $sent->getId(), $owner, ['name' => 'Autre nom']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testReopeningPausesTheActiveListAndRefusesAListThatIsNotSent(): void
    {
        // Given : une liste envoyée, puis une nouvelle liste active créée à sa place
        $owner = $this->savedUser();
        $sent = $this->savedSendableList($owner);
        $this->request('POST', '/api/v1/shopping-lists/' . $sent->getId() . '/send', $owner);
        $this->request('POST', '/api/v1/shopping-lists', $owner);
        $newId = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame(ShoppingListStatus::SENT, $this->shoppingLists()->findOneById($sent->getId())->getStatus());

        // When : la liste active n'est pas envoyée, on ne la rouvre pas
        $this->request('POST', "/api/v1/shopping-lists/$newId/reopen", $owner);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_NOT_SENT']], $this->jsonBody());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($newId)->getStatus());

        // When : la rouverture de la liste envoyée met l'autre en pause
        $this->request('POST', '/api/v1/shopping-lists/' . $sent->getId() . '/reopen', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingLists()->findOneById($sent->getId())->getStatus());
        $this->assertSame(ShoppingListStatus::PAUSED, $this->shoppingLists()->findOneById($newId)->getStatus());
    }
}

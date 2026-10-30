<?php

namespace Repas\Tests\Repas\InputAdapter;


use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Suppression d'un type de recette avec les vrais bus et la vraie base : les recettes de tous les utilisateurs passent dans le type
 * choisi (leur version augmente), le type disparaît, et un refus n'écrit rien.
 */
class RecipeTypeRemovalApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testTheRecipesOfEveryUserMoveToTheChosenTypeAndTheTypeDisappears(): void
    {
        // Given : deux auteurs ont une recette dans le type supprimé, un troisième dans un type voisin
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser();
        $starter = new RecipeTypeBuilder()->isStarter()->build();
        $meal = new RecipeTypeBuilder()->isMeal()->build();
        $dessert = new RecipeTypeBuilder()->isDessert()->build();
        $inStarter = $this->savedRecipe($admin, $starter, 'aaaaaaaa-0000-4000-8000-000000000001');
        $alsoInStarter = $this->savedRecipe($other, $starter, 'aaaaaaaa-0000-4000-8000-000000000002');
        $inDessert = $this->savedRecipe($other, $dessert, 'aaaaaaaa-0000-4000-8000-000000000003');
        $this->persister->saveRecipeType($meal);
        $versionBefore = $this->recipe($inStarter)->getVersion();

        // When
        $this->request('DELETE', '/api/v1/recipe-types/entrée?transferTo=plat', $admin);

        // Then : le type n'existe plus, ses recettes sont dans « plat » avec une version de plus, l'autre type n'a pas bougé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertFalse(static::getContainer()->get(RecipeTypeRepository::class)->exists('entrée'));
        foreach ([$inStarter, $alsoInStarter] as $id) {
            $this->assertSame('plat', $this->recipe($id)->getType()->getSlug());
        }
        $this->assertSame($versionBefore + 1, $this->recipe($inStarter)->getVersion());
        $this->assertSame('dessert', $this->recipe($inDessert)->getType()->getSlug());
        $this->request('GET', '/api/v1/recipe-types', $other);
        $this->assertSame(['plat', 'dessert'], array_column($this->jsonBody()['member'], 'slug'));
    }

    public function testRefusalsWriteNothing(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $starter = new RecipeTypeBuilder()->isStarter()->build();
        $recipeId = $this->savedRecipe($admin, $starter, 'aaaaaaaa-0000-4000-8000-000000000001');

        // When & Then : vers lui-même, vers un type inconnu, sans type de transfert, type inconnu
        $this->request('DELETE', '/api/v1/recipe-types/entrée?transferTo=entrée', $admin);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipe-types/entrée?transferTo=inconnu', $admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipe-types/entrée', $admin);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipe-types/inconnu?transferTo=entrée', $admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());

        $this->assertTrue(static::getContainer()->get(RecipeTypeRepository::class)->exists('entrée'));
        $this->assertSame('entrée', $this->recipe($recipeId)->getType()->getSlug());
    }

    public function testAUserWhoIsNotAnAdministratorCannotRemoveATypeAndKeepsHisRecipes(): void
    {
        // Given
        $user = $this->savedUser();
        $starter = new RecipeTypeBuilder()->isStarter()->build();
        $recipeId = $this->savedRecipe($user, $starter, 'aaaaaaaa-0000-4000-8000-000000000001');
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());

        // When
        $this->request('DELETE', '/api/v1/recipe-types/entrée?transferTo=plat', $user);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame('entrée', $this->recipe($recipeId)->getType()->getSlug());
    }

    private function savedRecipe(User $author, RecipeType $type, string $id): string
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withId($id)->withAuthor($author)->withRecipeType($type)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe->getId();
    }

    /**
     * Relue depuis la base : le déplacement est une écriture en masse, qui ne met pas à jour les entités déjà chargées par ce test.
     */
    private function recipe(string $id): Recipe
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return static::getContainer()->get(RecipeRepository::class)->findOneById($id);
    }

    private function request(string $method, string $uri, User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
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
}

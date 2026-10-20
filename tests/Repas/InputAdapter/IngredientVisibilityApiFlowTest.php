<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le chantier « ingrédients propres à chaque utilisateur » (ADR 0028, ING-002) de bout en bout, avec les vrais bus, la vraie base, le
 * voter et les listeners : recette -> liste de courses -> préférences du drive, pour un utilisateur qui a adopté un commun, pour un qui
 * n'a rien adopté, et pour un administrateur.
 *
 * Les communs sont la farine et le sucre (rayon boulangerie, achetés au kilo, comptés au gramme). `adopter` a adopté la farine ; `plain`
 * et `admin` n'ont aucun alias. Les clients envoient TOUJOURS les slugs des communs (`farine`, `sucre`) : c'est ce que fait un onglet
 * ouvert avant l'adoption, une liste en cache, ou le brouillon d'un envoi resté sur le téléphone.
 */
class IngredientVisibilityApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isSugar()->build());
    }

    public function testAnAdopterSeesOnlyHisCopyEverywhereAndEveryWriteLandsOnIt(): void
    {
        // Given : il a adopté la farine, pas le sucre
        $adopter = $this->savedUser();
        $copy = $this->adopt($adopter, 'farine');
        $this->assertSame('farine' . $adopter->getId(), $copy);

        // Then : le choix pour une recette, le rayon et la recherche montrent sa copie à la place du commun, et le sucre commun
        $this->request('GET', '/api/v1/ingredients', $adopter);
        $this->assertSame([$copy, 'sucre'], array_column($this->jsonBody()['member'], 'slug'));
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $adopter);
        $this->assertSame([$copy, 'sucre'], array_column($this->jsonBody()['member'], 'slug'));
        $this->request('GET', '/api/v1/ingredients/search?q=farine', $adopter);
        $this->assertSame([$copy], array_column($this->jsonBody()['member'], 'slug'));

        // Then : la fiche du commun reste lisible, en lecture seule
        $this->request('GET', '/api/v1/ingredients/farine', $adopter);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['farine', false], [$this->jsonBody()['slug'], $this->jsonBody()['canEdit']]);

        // When : il enregistre une recette avec les slugs des communs (le sucre n'a jamais été adopté)
        $this->request('POST', '/api/v1/recipes', $adopter, $this->recipeBody());
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $recipeId = basename($this->client->getResponse()->headers->get('Location'));

        // Then : les lignes portent ses copies ; le sucre a été adopté à la première utilisation
        $this->assertEqualsCanonicalizing([$copy, 'sucre' . $adopter->getId()], $this->recipeSlugs($recipeId));
        $this->assertSame(['first-use'], $this->column('SELECT source FROM ingredient_alias WHERE user_id = ? AND alias_slug = ?', $adopter->getId(), 'sucre'));
        $this->assertSame('sucre', $this->column('SELECT adopted_from FROM ingredient WHERE slug = ?', 'sucre' . $adopter->getId())[0]);
        // Aucun événement de création : le compteur « ingrédients créés » n'a pas bougé
        $this->assertSame(0, $this->createdIngredients($adopter));

        // When : il la modifie avec les mêmes slugs de communs, puis la planifie dans sa liste active
        $this->request('PUT', '/api/v1/recipes/' . $recipeId, $adopter, $this->recipeBody('Pâtes sablées'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $listId = $this->savedActiveList($adopter);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $adopter);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : la recette comme la liste portent les copies, et un seul ingrédient adopté par commun
        $this->assertEqualsCanonicalizing([$copy, 'sucre' . $adopter->getId()], $this->recipeSlugs($recipeId));
        $this->assertEqualsCanonicalizing([$copy, 'sucre' . $adopter->getId()], $this->listSlugs($listId));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM ingredient WHERE adopted_from IS NOT NULL AND creator = ?', $adopter->getId()));

        // When : il ajoute puis retire de sa liste l'ancien slug du commun (liste en cache)
        $quantity = fn(string $slug): float => (float) $this->column('SELECT quantity FROM shopping_list_row WHERE shopping_list = ? AND ingredient = ?', $listId, $slug)[0];
        $before = $quantity($copy);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/increase', $adopter);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertGreaterThan($before, $quantity($copy));
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/decrease', $adopter);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals($before, $quantity($copy));

        // Then : la liste n'a jamais reçu la ligne du commun masqué
        $this->assertNotContains('farine', $this->listSlugs($listId));
        $this->assertNotContains('sucre', $this->listSlugs($listId));
    }

    public function testTheDriveChoicesOfAnAdopterAreKeptUnderHisCopyWhateverSlugTheClientSends(): void
    {
        // Given : il a adopté la farine et a un magasin
        $adopter = $this->savedUser();
        $copy = $this->adopt($adopter, 'farine');
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($adopter->getId())->build());

        // When : le brouillon d'envoi (ancien slug) mémorise un produit, puis la copie (slug neuf) un second
        $this->request('PUT', '/api/v1/drive/choices', $adopter, $this->choicesBody('farine', '555111'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/drive/choices', $adopter, $this->choicesBody($copy, '777888'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : une seule préférence, sous la copie, avec les deux produits ; aucune sous le commun
        $this->assertSame([$copy], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $adopter->getId()));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $adopter);
        $this->assertSame([$copy], array_column($this->jsonBody()['member'], 'ingredientSlug'));
        $this->assertSame(['555111', '777888'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']));

        // When : la liste envoyée de l'utilisateur porte la copie ; le plan d'envoi (GET /drive/plans/{id}) cherche la préférence sous le slug de la ligne
        $sentListId = $this->savedSentList($adopter);
        $this->request('GET', "/api/v1/drive/plans/$sentListId", $adopter);

        // Then : la préférence mémorisée avec l'ancien slug est retrouvée, la ligne n'est pas « nouvelle »
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $line = $this->jsonBody()['lines'][0];
        $this->assertSame([$copy, 'KNOWN'], [$line['ingredientSlug'], $line['status']]);
        $this->assertSame(['555111', '777888'], array_column($line['products'], 'retailerProductId'));

        // When : l'écran de gestion remplace la préférence avec l'ancien slug
        $this->request('PUT', '/api/v1/drive/choices/farine', $adopter, ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => true, 'accepted' => [], 'rejected' => []]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : « j'en ai toujours » est sur la copie, toujours une seule préférence
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $adopter);
        $this->assertSame([[$copy, true]], array_map(static fn(array $c): array => [$c['ingredientSlug'], $c['haveAtHome']], $this->jsonBody()['member']));

        // When : il l'oublie avec l'ancien slug
        $this->request('DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456', $adopter);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $adopter->getId()));
    }

    public function testWithoutAnyAliasNothingChangesForTheUserNorTheAdministrator(): void
    {
        // Given : un utilisateur qui n'a rien adopté, un administrateur, et un autre utilisateur qui a adopté la farine
        $plain = $this->savedUser();
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser();
        $otherCopy = $this->adopt($other, 'farine');
        foreach ([$plain, $admin] as $user) {
            static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        }

        foreach ([$plain, $admin] as $user) {
            // Then : les communs sont visibles tels quels, jamais la copie d'un autre
            $this->request('GET', '/api/v1/ingredients', $user);
            $this->assertSame(['farine', 'sucre'], array_column($this->jsonBody()['member'], 'slug'));
            $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $user);
            $this->assertSame(['farine', 'sucre'], array_column($this->jsonBody()['member'], 'slug'));
            $this->request('GET', '/api/v1/ingredients/search?q=farine', $user);
            $this->assertSame(['farine'], array_column($this->jsonBody()['member'], 'slug'));
            $this->request('GET', '/api/v1/ingredients/' . $otherCopy, $user);
            $this->assertSame($user === $admin ? 200 : 404, $this->client->getResponse()->getStatusCode());

            // When : recette, liste, préférences, avec les slugs des communs
            $this->request('POST', '/api/v1/recipes', $user, $this->recipeBody());
            $this->assertSame(201, $this->client->getResponse()->getStatusCode());
            $recipeId = basename($this->client->getResponse()->headers->get('Location'));
            $listId = $this->savedActiveList($user);
            $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $user);
            $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/increase', $user);
            $this->request('PUT', '/api/v1/drive/choices', $user, $this->choicesBody('farine', '555111'));

            // Then : tout reste sur les communs partagés, rien n'est adopté pour lui
            $this->assertEqualsCanonicalizing(['farine', 'sucre'], $this->recipeSlugs($recipeId));
            $this->assertEqualsCanonicalizing(['farine', 'sucre'], $this->listSlugs($listId));
            $this->assertSame(['farine'], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $user->getId()));
            $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient_alias WHERE user_id = ?', $user->getId()));
            $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient WHERE creator = ?', $user->getId()));
        }

        // Then : la fiche du commun reste modifiable par l'administrateur seulement
        $this->request('GET', '/api/v1/ingredients/farine', $admin);
        $this->assertTrue($this->jsonBody()['canEdit']);
        $this->request('GET', '/api/v1/ingredients/farine', $plain);
        $this->assertFalse($this->jsonBody()['canEdit']);
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeBody(string $name = 'Pâte sablée'): array
    {
        return [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => 'farine', 'unitSlug' => 'gramme', 'quantity' => 250],
                ['ingredientSlug' => 'sucre', 'unitSlug' => 'gramme', 'quantity' => 100],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choicesBody(string $ingredientSlug, string $retailerProductId): array
    {
        return ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => $ingredientSlug, 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => $retailerProductId, 'label' => 'Farine ' . $retailerProductId]],
        ]];
    }

    private function adopt(User $user, string $commonSlug): string
    {
        return static::getContainer()->get(CommandBusInterface::class)->dispatch(new AdoptCommonIngredientCommand($user->getId(), $commonSlug, 'migration-2026-10-21'));
    }

    private function savedSentList(User $owner): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->addIngredient(new IngredientBuilder()->isFlour()->withCreator($owner, true))->withStatus(ShoppingListStatus::SENT)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    private function savedActiveList(User $owner): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * @return list<string>
     */
    private function recipeSlugs(string $recipeId): array
    {
        return $this->column('SELECT ingredient FROM recipe_row WHERE recipe = ?', $recipeId);
    }

    /**
     * @return list<string>
     */
    private function listSlugs(string $listId): array
    {
        return $this->column('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ?', $listId);
    }

    private function createdIngredients(User $user): int
    {
        $this->entityManager->clear();

        return (int) (static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients'] ?? 0);
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
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

    /**
     * @return list<string>
     */
    private function column(string $sql, string ...$params): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getConnection()->fetchFirstColumn($sql, $params);
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
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
 * Le monde sans ingrédient commun (ADR 0028, amendement du 2026-10-20) de bout en bout, avec les vrais bus, la vraie base, le voter et les
 * listeners : recette -> liste de courses -> préférences du drive, pour le propriétaire du catalogue, pour un compte qui a reçu une copie, pour
 * un compte neuf amorcé et pour un autre administrateur. Chacun ne voit, ne modifie et n'utilise que les siens.
 *
 * Après la passation, les anciens communs (la farine et le sucre, rayon boulangerie, achetés au kilo, comptés au gramme) sont ceux du
 * propriétaire du catalogue, aux slugs inchangés (`farine`, `sucre`). `copier` a reçu une copie de la farine seulement (et son alias),
 * `fresh` a reçu les deux à son activation, `otherAdmin` n'a rien. Les clients envoient parfois encore les anciens slugs (un onglet ouvert,
 * une liste en cache, le brouillon d'un envoi resté sur le téléphone).
 */
class IngredientVisibilityApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $copier;
    private User $fresh;
    private User $otherAdmin;
    private string $copierFlour;
    private string $freshFlour;
    private string $freshSugar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        $this->owner = $this->savedUser(admin: true);
        $this->copier = $this->savedUser();
        $this->fresh = $this->savedUser();
        $this->otherAdmin = $this->savedUser(admin: true);
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withCreator($this->owner)->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isSugar()->withCreator($this->owner)->build());
        $this->copierFlour = $this->received($this->copier, new IngredientBuilder()->isFlour());
        $this->freshFlour = $this->received($this->fresh, new IngredientBuilder()->isFlour());
        $this->freshSugar = $this->received($this->fresh, new IngredientBuilder()->isSugar());
    }

    public function testEachAccountSeesOnlyItsOwnIngredientsEverywhereAndNeverTwice(): void
    {
        // Then : le choix pour une recette, le rayon et la recherche
        $this->assertSame(['farine', 'sucre'], $this->slugsOfTheViews($this->owner), 'le propriétaire : exactement les mêmes qu\'avant');
        $this->assertSame([$this->copierFlour], $this->slugsOfTheViews($this->copier));
        $this->assertSame([$this->freshFlour, $this->freshSugar], $this->slugsOfTheViews($this->fresh));
        $this->assertSame([], $this->slugsOfTheViews($this->otherAdmin), 'un administrateur ne voit pas les ingrédients des autres');

        // Then : la recherche de « farine » donne un seul résultat à chacun, le sien
        foreach ([[$this->owner, 'farine'], [$this->copier, $this->copierFlour], [$this->fresh, $this->freshFlour]] as [$user, $expected]) {
            $this->request('GET', '/api/v1/ingredients/search?q=farine', $user);
            $this->assertSame([$expected], array_column($this->jsonBody()['member'], 'slug'));
            $this->assertSame([true], array_column($this->jsonBody()['member'], 'canEdit'));
        }
    }

    public function testTheOwnerOfTheCatalogDoesEverythingWithHisIngredientsAsBefore(): void
    {
        // Given
        $owner = $this->owner;

        // When : une recette avec les ingrédients du catalogue, puis sa modification et sa planification
        $this->request('POST', '/api/v1/recipes', $owner, $this->recipeBody('farine', 'sucre'));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $recipeId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('PUT', '/api/v1/recipes/' . $recipeId, $owner, $this->recipeBody('farine', 'sucre', 'Pâtes sablées'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $listId = $this->savedActiveList($owner);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : aucun changement de slug, aucune copie, aucun alias
        $this->assertEqualsCanonicalizing(['farine', 'sucre'], $this->recipeSlugs($recipeId));
        $this->assertEqualsCanonicalizing(['farine', 'sucre'], $this->listSlugs($listId));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient_alias WHERE user_id = ?', $owner->getId()));
        $this->assertSame(5, $this->scalar('SELECT COUNT(*) FROM ingredient'), 'les 2 du propriétaire, 1 chez copier, 2 chez fresh : rien de plus');

        // When : il ajoute puis retire un ingrédient de sa liste, et mémorise un produit du drive
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/increase', $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/decrease', $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($owner->getId())->build());
        $this->request('PUT', '/api/v1/drive/choices', $owner, $this->choicesBody('farine', '555111'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['farine'], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $owner->getId()));

        // Then : il modifie et supprime SES ingrédients (la suppression est refusée tant qu'une recette l'utilise)
        $this->request('PUT', '/api/v1/ingredients/farine', $owner, $this->ingredientBody('Farine de blé'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/ingredients/farine', $owner);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAccountThatReceivedACopyKeepsWorkingWithTheOldSlugAndEveryWriteLandsOnItsCopy(): void
    {
        // Given
        $copier = $this->copier;

        // When : il enregistre une recette avec l'ancien slug de la farine (un onglet resté ouvert), puis la modifie et la planifie
        $this->request('POST', '/api/v1/recipes', $copier, $this->recipeBody('farine', 'farine', 'Pâte sablée'));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $recipeId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('PUT', '/api/v1/recipes/' . $recipeId, $copier, $this->recipeBody('farine', 'farine', 'Pâtes sablées'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $listId = $this->savedActiveList($copier);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $copier);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : les lignes portent sa copie, jamais l'ingrédient du propriétaire
        $this->assertSame([$this->copierFlour, $this->copierFlour], $this->recipeSlugs($recipeId));
        $this->assertContains($this->copierFlour, $this->listSlugs($listId));
        $this->assertNotContains('farine', $this->listSlugs($listId));

        // When : il ajoute puis retire de sa liste l'ancien slug (liste en cache)
        $quantity = fn(string $slug): float => (float) $this->column('SELECT quantity FROM shopping_list_row WHERE shopping_list = ? AND ingredient = ?', $listId, $slug)[0];
        $before = $quantity($this->copierFlour);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/increase', $copier);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertGreaterThan($before, $quantity($this->copierFlour));
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/decrease', $copier);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals($before, $quantity($this->copierFlour));
        $this->assertNotContains('farine', $this->listSlugs($listId));
    }

    public function testAnOldSlugTheAccountNeverReceivedACopyOfIsUnknownAndNothingIsAdopted(): void
    {
        // Given : `copier` n'a reçu que la farine ; le sucre est l'ingrédient du propriétaire
        $copier = $this->copier;
        $ingredientsBefore = $this->scalar('SELECT COUNT(*) FROM ingredient');
        $aliasesBefore = $this->scalar('SELECT COUNT(*) FROM ingredient_alias');

        // When : recette avec le sucre, ajout à la liste
        $this->request('POST', '/api/v1/recipes', $copier, $this->recipeBody('farine', 'sucre'));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->savedActiveList($copier);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/sucre/increase', $copier);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // Then : « ingrédient inconnu », aucune adoption (ni copie, ni alias, ni ligne sur l'ingrédient d'un autre)
        $this->assertSame($ingredientsBefore, $this->scalar('SELECT COUNT(*) FROM ingredient'));
        $this->assertSame($aliasesBefore, $this->scalar('SELECT COUNT(*) FROM ingredient_alias'));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $copier->getId()));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM shopping_list_row r JOIN shopping_list l ON l.id = r.shopping_list WHERE l.owner = ? AND r.ingredient = 'sucre'", $copier->getId()));
    }

    public function testTheSheetOfAnOldSlugIsNoLongerReadable(): void
    {
        // When : le compte qui a reçu une copie relit la fiche par l'ancien slug (un écran périmé)
        $this->request('GET', '/api/v1/ingredients/farine', $this->copier);

        // Then : l'ingrédient est celui du propriétaire (404), la liste se recharge
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/ingredients/' . $this->copierFlour, $this->copier);
        $this->assertSame([200, true, $this->copierFlour], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['canEdit'], $this->jsonBody()['slug']]);
    }

    public function testTheDriveChoicesOfAnAccountThatReceivedACopyAreKeptUnderItsCopyWhateverSlugTheClientSends(): void
    {
        // Given : il a une copie de la farine et un magasin
        $copier = $this->copier;
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($copier->getId())->build());

        // When : le brouillon d'envoi (ancien slug) mémorise un produit, puis la copie (slug neuf) un second
        $this->request('PUT', '/api/v1/drive/choices', $copier, $this->choicesBody('farine', '555111'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/drive/choices', $copier, $this->choicesBody($this->copierFlour, '777888'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : une seule préférence, sous la copie, avec les deux produits ; aucune sous l'ancien slug
        $this->assertSame([$this->copierFlour], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $copier->getId()));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $copier);
        $this->assertSame([$this->copierFlour], array_column($this->jsonBody()['member'], 'ingredientSlug'));
        $this->assertSame(['555111', '777888'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']));

        // When : la liste envoyée de l'utilisateur porte la copie ; le plan d'envoi cherche la préférence sous le slug de la ligne
        $sentListId = $this->savedSentList($copier, $this->copierFlour);
        $this->request('GET', "/api/v1/drive/plans/$sentListId", $copier);

        // Then : la préférence mémorisée avec l'ancien slug est retrouvée, la ligne n'est pas « nouvelle »
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $line = $this->jsonBody()['lines'][0];
        $this->assertSame([$this->copierFlour, 'KNOWN'], [$line['ingredientSlug'], $line['status']]);
        $this->assertSame(['555111', '777888'], array_column($line['products'], 'retailerProductId'));

        // When : l'écran de gestion remplace la préférence avec l'ancien slug
        $this->request('PUT', '/api/v1/drive/choices/farine', $copier, ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => true, 'accepted' => [], 'rejected' => []]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : « j'en ai toujours » est sur la copie, toujours une seule préférence
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $copier);
        $this->assertSame([[$this->copierFlour, true]], array_map(static fn(array $c): array => [$c['ingredientSlug'], $c['haveAtHome']], $this->jsonBody()['member']));

        // When : il l'oublie avec l'ancien slug
        $this->request('DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456', $copier);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $copier->getId()));
    }

    public function testAnAdministratorWhoIsNotTheOwnerHasNoPassThroughOnTheIngredientsOfOthers(): void
    {
        // Given : un administrateur qui n'a pas d'ingrédient, et les ingrédients du propriétaire et d'un autre compte
        $admin = $this->otherAdmin;

        foreach (['farine', $this->copierFlour] as $slug) {
            // When / Then : il ne les lit pas (404), ne les modifie pas (403), ne les supprime pas (403)
            $this->request('GET', '/api/v1/ingredients/' . $slug, $admin);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), "GET $slug");
            $this->request('PUT', '/api/v1/ingredients/' . $slug, $admin, $this->ingredientBody('Nom changé'));
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "PUT $slug");
            $this->request('DELETE', '/api/v1/ingredients/' . $slug, $admin);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "DELETE $slug");
            $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $slug . '/increase', $admin);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [404, 422], "liste $slug");
        }
        $this->assertSame(['Farine', 'Farine'], [$this->nameOf('farine'), $this->nameOf($this->copierFlour)], 'rien n\'a changé');

        // When : il écrit une recette avec l'ingrédient d'un autre
        $this->request('POST', '/api/v1/recipes', $admin, $this->recipeBody('farine', 'sucre'));

        // Then : inconnu
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Ce qu'un compte voit dans le choix pour une recette, dans le rayon et par la recherche : les trois lectures doivent dire la même chose.
     *
     * @return list<string> les slugs, triés, une fois chacun
     */
    private function slugsOfTheViews(User $user): array
    {
        $this->request('GET', '/api/v1/ingredients', $user);
        $all = array_column($this->jsonBody()['member'], 'slug');
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $user);
        $inTheDepartment = array_column($this->jsonBody()['member'], 'slug');
        $found = [];
        foreach (['farine', 'sucre'] as $needle) {
            $this->request('GET', '/api/v1/ingredients/search?q=' . $needle, $user);
            array_push($found, ...array_column($this->jsonBody()['member'], 'slug'));
        }
        sort($all);
        sort($inTheDepartment);
        sort($found);
        $this->assertSame($all, $inTheDepartment, 'le rayon et le choix pour une recette');
        $this->assertSame($all, $found, 'la recherche');
        $this->assertSame($all, array_values(array_unique($all)), 'jamais deux fois le même');

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeBody(string $first, string $second, string $name = 'Pâte sablée'): array
    {
        return [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => $first, 'unitSlug' => 'gramme', 'quantity' => 250],
                ['ingredientSlug' => $second, 'unitSlug' => 'gramme', 'quantity' => 100],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ingredientBody(string $name): array
    {
        return ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000];
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

    /**
     * La copie, reçue à l'activation du compte, d'un ingrédient du propriétaire du catalogue : son slug, son origine et son alias.
     */
    private function received(User $user, IngredientBuilder $builder): string
    {
        $origin = $builder->build()->getSlug();
        $copy = $builder->withCreator($user, true)->withAdoptedFrom($origin)->build();
        $this->persister->saveIngredient($copy);
        $this->persister->saveIngredientAlias(IngredientAlias::create($user->getId(), $copy->getAdoptedFrom(), $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-10-21 03:00:00')));

        return $copy->getSlug();
    }

    private function nameOf(string $slug): string
    {
        return (string) $this->column('SELECT name FROM ingredient WHERE slug = ?', $slug)[0];
    }

    private function savedSentList(User $owner, string $flourSlug): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->addIngredient(new IngredientBuilder()->isFlour()->withSlug($flourSlug)->withCreator($owner))->withStatus(ShoppingListStatus::SENT)->build();
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

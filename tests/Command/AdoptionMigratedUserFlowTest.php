<?php

namespace Repas\Tests\Command;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Infrastructure\Loader\IngredientAdoptionReportPostgreSQLLoader;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Non-régression de la migration d'adoption (ING-003) côté API : un compte migré continue de tout faire, de bout en bout, avec les vrais
 * bus, la vraie base, le voter et les listeners. Ses données (recette, liste planifiée, préférence du drive) ont été créées AVANT la
 * migration, par l'API, sur les communs, comme en production. Les communs sont la farine et le sucre.
 *
 * Après la migration : il ne voit plus que ses copies (rayon, recherche, choix d'une recette), ses recettes et sa liste se lisent comme
 * avant, retirer un repas de la liste retrouve les lignes (la recette et la liste ont été repointées ensemble), ajouter et retirer un
 * ingrédient de la liste avec l'ancien slug d'un commun atterrit sur la copie, copier la recette d'un autre se branche sur ses copies.
 */
class AdoptionMigratedUserFlowTest extends DatabaseTestCase
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
        // Les anciens communs : la forme de la base d'avant la passation, de laquelle part la migration
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withoutCreator()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isSugar()->withoutCreator()->build());
    }

    public function testAMigratedUserKeepsDoingEverythingAndSeesOnlyHisCopies(): void
    {
        // Given : avant la migration (la base d'avant la passation, qui n'est plus lisible par l'API : les données sont posées telles quelles), un
        // compte a une recette sur les communs, planifiée dans sa liste, et un produit du drive ; un autre compte (sans recette ni ingrédient
        // personnel) a une liste ; l'administrateur a une recette sur les communs
        $user = $this->savedUser();
        $bystander = $this->savedUser();
        $admin = $this->savedUser(admin: true);
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $recipeId = $this->savedRecipeOnTheCommons($user, 'Pâte sablée');
        $listId = $this->savedListPlanning($user, $recipeId);
        $this->request('PUT', '/api/v1/drive/choices', $user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Farine 1 kg']],
        ]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $adminRecipeId = $this->savedRecipeOnTheCommons($admin, 'Recette de l\'administrateur');
        $bystanderList = $this->savedListPlanning($bystander, null);

        $this->request('GET', '/api/v1/recipes/' . $recipeId, $user);
        $recipeBefore = $this->jsonBody();
        $etagBefore = $this->client->getResponse()->headers->get('ETag');
        $listRowsBefore = $this->listRows($listId);
        $this->assertSame(['farine', 'sucre'], array_column($listRowsBefore, 'ingredient'));

        // When : la migration de ce compte
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $status = $tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [IngredientAdoptionReportPostgreSQLLoader::referenceOf($user->getId())]]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->entityManager->clear();
        $copy = fn(string $slug): string => $slug . $user->getId();

        // Then : il ne voit plus que ses copies, dans le choix pour une recette, le rayon et la recherche
        $this->request('GET', '/api/v1/ingredients', $user);
        $this->assertSame([$copy('farine'), $copy('sucre')], array_column($this->jsonBody()['member'], 'slug'));
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $user);
        $this->assertSame([$copy('farine'), $copy('sucre')], array_column($this->jsonBody()['member'], 'slug'));
        $this->assertTrue($this->jsonBody()['member'][0]['canEdit'], 'une copie est à lui : modifiable');
        $this->request('GET', '/api/v1/ingredients/search?q=farine', $user);
        $this->assertSame([$copy('farine')], array_column($this->jsonBody()['member'], 'slug'));
        // …et le compte qui n'a rien reçu comme l'administrateur ne voient rien qui soit à lui (les communs ne sont plus partagés)
        foreach ([$bystander, $admin] as $other) {
            $this->request('GET', '/api/v1/ingredients', $other);
            $this->assertSame([], array_column($this->jsonBody()['member'], 'slug'));
        }

        // Then : sa recette se lit comme avant, mêmes noms et mêmes quantités, avec ses copies ; sa version a monté (un If-Match périmé = 412)
        $this->request('GET', '/api/v1/recipes/' . $recipeId, $user);
        $recipeAfter = $this->jsonBody();
        $this->assertSame($recipeBefore['name'], $recipeAfter['name']);
        $this->assertSame(
            array_map(static fn(array $row): array => [$row['ingredientName'], $row['quantity'], $row['unitSlug']], $recipeBefore['rows']),
            array_map(static fn(array $row): array => [$row['ingredientName'], $row['quantity'], $row['unitSlug']], $recipeAfter['rows']),
        );
        $this->assertSame([$copy('farine'), $copy('sucre')], array_column($recipeAfter['rows'], 'ingredientSlug'));
        $this->assertNotSame($etagBefore, $this->client->getResponse()->headers->get('ETag'));
        $this->request('PUT', '/api/v1/recipes/' . $recipeId, $user, $this->recipeBody('Renommée', [$copy('farine'), $copy('sucre')]), ['HTTP_IF_MATCH' => $etagBefore]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());

        // Then : sa liste porte les mêmes quantités, sur ses copies
        $listRowsAfter = $this->listRows($listId);
        $this->assertSame(array_column($listRowsBefore, 'quantity'), array_column($listRowsAfter, 'quantity'));
        $this->assertSame([$copy('farine'), $copy('sucre')], array_column($listRowsAfter, 'ingredient'));
        $this->request('GET', "/api/v1/shopping-lists/$listId", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // When : il ajoute puis retire de sa liste l'ancien slug d'un commun (liste en cache, onglet ouvert)
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/increase', $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/farine/decrease', $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(array_column($listRowsBefore, 'quantity'), array_column($this->listRows($listId), 'quantity'));
        $this->assertNotContains('farine', array_column($this->listRows($listId), 'ingredient'));

        // When : il retire le repas de sa liste : la recette et la liste ayant été repointées ENSEMBLE, les lignes se retrouvent et se vident
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->listRows($listId), 'retirer le repas retrouve les lignes repointées (aucune quantité orpheline)');

        // When : il le replanifie, puis copie la recette de l'administrateur (qui a gardé les communs)
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $ingredientsBefore = (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient');
        $this->request('POST', "/api/v1/recipes/$adminRecipeId/copy", $user);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copiedRecipeId = basename($this->client->getResponse()->headers->get('Location'));

        // Then : la copie se branche sur SES copies (provenance), sans créer aucun ingrédient ni écraser quoi que ce soit
        $this->assertEqualsCanonicalizing([$copy('farine'), $copy('sucre')], $this->entityManager->getConnection()->fetchFirstColumn('SELECT ingredient FROM recipe_row WHERE recipe = ?', [$copiedRecipeId]));
        $this->assertSame($ingredientsBefore, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient'));

        // Then : sa préférence du drive est lisible sous la copie
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
        $this->assertSame([$copy('farine')], array_column($this->jsonBody()['member'], 'ingredientSlug'));

        // Then : l'administrateur et le compte qui n'a rien reçu n'ont rien perdu de leurs communs
        $this->assertEqualsCanonicalizing(['farine', 'sucre'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT ingredient FROM recipe_row WHERE recipe = ?', [$adminRecipeId]));
        $this->assertSame(['farine', 'sucre'], array_column($this->listRows($bystanderList), 'ingredient'));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id IN (?, ?)', [$bystander->getId(), $admin->getId()]));
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, mixed>
     */
    private function recipeBody(string $name = 'Pâte sablée', array $slugs = ['farine', 'sucre']): array
    {
        return [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => $slugs[0], 'unitSlug' => 'gramme', 'quantity' => 250],
                ['ingredientSlug' => $slugs[1], 'unitSlug' => 'gramme', 'quantity' => 100],
            ],
        ];
    }

    /**
     * Une recette sur les anciens communs (farine et sucre, sans créateur), posée telle quelle dans la base d'avant la passation.
     */
    private function savedRecipeOnTheCommons(User $author, string $name): string
    {
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isFlour()->withoutCreator())->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isSugar()->withoutCreator())->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))
            ->build();
        $this->persister->saveRecipe($recipe);

        return $recipe->getId();
    }

    /**
     * La liste active du compte, avec le repas de cette recette et les lignes de courses correspondantes (comme après `PlannedMeal`) ; sans
     * recette, une liste avec les deux communs en lignes.
     */
    private function savedListPlanning(User $owner, ?string $recipeId): string
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        $recipe = $recipeId === null ? null : static::getContainer()->get(RecipeRepository::class)->findOneById($recipeId);
        if ($recipe !== null) {
            $builder->addRecipe($recipe);
        }
        $list = $builder->build();
        foreach (['farine' => 'kilo', 'sucre' => 'kilo'] as $slug => $unit) {
            $list->addRow($slug, $unit, $recipe === null ? 1.0 : ($slug === 'farine' ? 0.25 : 0.1));
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * @return list<array{ingredient: string, quantity: float}>
     */
    private function listRows(string $listId): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn(array $row): array => ['ingredient' => $row[0], 'quantity' => (float) $row[1]],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]),
        );
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, User $user, ?array $body = null, array $headers = []): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            ...$headers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

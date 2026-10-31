<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Une recette éclatée entre plusieurs listes (ADR 0036 décisions 3 et 7 à 12, LST-005) de bout en bout : vrais bus, vraie base, vraie sécurité.
 * Un hachis parmentier dont la viande va dans « Boucherie », les légumes dans « Marché » et le reste dans la liste principale :
 *
 *   - Steak haché 500 g (rayon viande, acheté au gramme)    : Boucherie
 *   - Pomme de terre 800 g (rayon légumes, achetée au kilo) : Marché
 *   - Oignon 2 (rayon légumes)                              : Marché
 *   - Beurre 50 g (rayon divers, acheté au gramme)          : la liste principale
 */
class SplitRecipeApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $plain;
    private Ingredient $steak;
    private Ingredient $potato;
    private Ingredient $onion;
    private Ingredient $butter;
    private Recipe $recipe;
    private string $butcherId;
    private string $marketId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->plain = $this->savedUser();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        $this->steak = $this->savedIngredient('Steak haché', new DepartmentBuilder()->isMeat(), new UnitBuilder()->isGramme(), new UnitBuilder()->isGramme());
        $this->potato = $this->savedIngredient('Pomme de terre', new DepartmentBuilder()->withSlug('legumes')->withName('Légumes'), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo());
        $this->onion = $this->savedIngredient('Oignon', new DepartmentBuilder()->withSlug('legumes')->withName('Légumes'), new UnitBuilder()->isUnite(), new UnitBuilder()->isUnite());
        $this->butter = $this->savedIngredient('Beurre', new DepartmentBuilder()->isMiscellaneous(), new UnitBuilder()->isGramme(), new UnitBuilder()->isGramme());
        $this->recipe = $this->savedRecipe($this->owner);

        $this->butcherId = $this->createdType($this->owner, 'Boucherie', ['viande']);
        $this->marketId = $this->createdType($this->owner, 'Marché', ['legumes']);
    }

    public function testThreeListsReceiveTheirMealAndTheirLines(): void
    {
        // Given : la liste principale est en cours, la Boucherie et le Marché n'ont pas de liste
        $main = $this->createdMainList($this->owner);

        // When
        $this->planned($this->owner);

        // Then : 200 et les listes qui ont reçu quelque chose, la liste principale d'abord, puis les types dans l'ordre de la recette
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lists = $this->jsonBody()['lists'];
        $this->assertSame([$main, null], [$lists[0]['id'], $lists[0]['typeId']]);
        $this->assertSame([['Boucherie', $this->butcherId], ['Marché', $this->marketId]], array_map(static fn(array $list): array => [$list['name'], $list['typeId']], array_slice($lists, 1)));
        $this->assertSame(['id', 'name', 'typeId'], array_keys($lists[0]));
        $this->assertCount(3, $lists);

        // And : chaque liste porte son repas et ses lignes, dans l'unité d'achat
        [$butcher, $market] = [$lists[1]['id'], $lists[2]['id']];
        $this->assertEqualsWithDelta([$this->butter->getSlug() => 50.0], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta([$this->steak->getSlug() => 500.0], $this->rows($butcher), 1e-9);
        $this->assertEqualsWithDelta([$this->onion->getSlug() => 2.0, $this->potato->getSlug() => 0.8], $this->rows($market), 1e-9);
        $groups = [$this->groupOf($main), $this->groupOf($butcher), $this->groupOf($market)];
        $this->assertNotNull($groups[0]);
        $this->assertSame([$groups[0], $groups[0]], [$groups[1], $groups[2]]);
        $this->assertSame([[$this->butter->getSlug()], [$this->steak->getSlug()]], [$this->placedOf($main), $this->placedOf($butcher)]);
        $this->assertEqualsCanonicalizing([$this->potato->getSlug(), $this->onion->getSlug()], $this->placedOf($market));

        // And : les trois listes sont en cours, la principale d'abord
        $this->request('GET', '/api/v1/shopping-lists/open', $this->owner);
        // (deux types créés dans la même seconde n'ont pas d'ordre garanti entre eux : la principale d'abord, puis les types)
        $ids = array_column($this->jsonBody()['member'], 'id');
        $this->assertSame($main, $ids[0]);
        $this->assertEqualsCanonicalizing([$butcher, $market], array_slice($ids, 1));
        $this->assertSame(['ACTIVE', 'ACTIVE', 'ACTIVE'], [$this->statusOf($main), $this->statusOf($butcher), $this->statusOf($market)]);
    }

    public function testChangingThePeopleActsOnTheThreeListsAndRemovingTheRecipeToo(): void
    {
        // Given
        [$main, $butcher, $market] = $this->plannedEverywhere();

        // When : on passe à 8 depuis le repas de la Boucherie
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($butcher) . '/serving', $this->owner, ['serving' => 8]);

        // Then : chaque liste a doublé SES lignes
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$this->butter->getSlug() => 100.0], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta([$this->steak->getSlug() => 1000.0], $this->rows($butcher), 1e-9);
        $this->assertEqualsWithDelta([$this->onion->getSlug() => 4.0, $this->potato->getSlug() => 1.6], $this->rows($market), 1e-9);
        $this->assertSame([8, 8, 8], [$this->servingOf($main), $this->servingOf($butcher), $this->servingOf($market)]);

        // When : on repasse à 2 depuis la liste principale
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($main) . '/serving', $this->owner, ['serving' => 2]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$this->steak->getSlug() => 250.0], $this->rows($butcher), 1e-9);
        $this->assertEqualsWithDelta([$this->onion->getSlug() => 1.0, $this->potato->getSlug() => 0.4], $this->rows($market), 1e-9);

        // When : on retire la recette (de la liste principale)
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $this->owner);

        // Then : plus de repas, plus de ligne nulle part ; les trois listes restent en cours
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        foreach ([$main, $butcher, $market] as $list) {
            $this->assertSame([[], 0], [$this->rows($list), $this->mealCount($list)], $list);
            $this->assertSame('ACTIVE', $this->statusOf($list));
        }
    }

    public function testIfOneListOfTheGroupIsSentNothingChangesAnywhere(): void
    {
        // Given : le Marché part au drive après la planification
        [$main, $butcher, $market] = $this->plannedEverywhere();
        $this->entityManager->getConnection()->executeStatement("UPDATE shopping_list SET status = 'SENT', sent_at = '2026-10-31 10:00:00' WHERE id = ?", [$market]);
        $before = $this->snapshot([$main, $butcher, $market]);

        // When / Then : changer les personnes, depuis n'importe quel repas du groupe
        foreach ([$main, $butcher, $market] as $list) {
            $this->request('PUT', '/api/v1/meals/' . $this->mealOf($list) . '/serving', $this->owner, ['serving' => 8]);
            $this->assertSame([409, ['error' => ['message' => 'SHOPPING_LIST_SENT']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()], $list);
            $this->assertSame($before, $this->snapshot([$main, $butcher, $market]), $list);
        }

        // And : retirer la recette
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $this->owner);
        $this->assertSame([409, ['error' => ['message' => 'SHOPPING_LIST_SENT']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame($before, $this->snapshot([$main, $butcher, $market]));
    }

    public function testAProductWhoseDestinationChangesAfterPlanningMovesNoLine(): void
    {
        // Given : le steak a été posé dans la Boucherie ; ensuite il est mis à part dans la liste principale
        [$main, $butcher] = $this->plannedEverywhere();
        $this->request('PUT', '/api/v1/ingredients/' . $this->steak->getSlug() . '/list-destination', $this->owner, ['destination' => 'main']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : on change les personnes
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($main) . '/serving', $this->owner, ['serving' => 8]);

        // Then : sa ligne a doublé là où elle était, la liste principale n'en a pas reçu
        $this->assertEqualsWithDelta([$this->steak->getSlug() => 1000.0], $this->rows($butcher), 1e-9);
        $this->assertArrayNotHasKey($this->steak->getSlug(), $this->rows($main));

        // When : on retire la recette
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $this->owner);

        // Then : la ligne du steak part de la Boucherie
        $this->assertSame([], $this->rows($butcher));
    }

    public function testAnAccountWithoutTypeKeepsTheBehaviourOfBefore(): void
    {
        // Given : un compte sans type, ses propres produits et la même recette
        $steak = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), new UnitBuilder()->isGramme(), new UnitBuilder()->isGramme(), $this->plain);
        $potato = $this->savedIngredient('Patate', new DepartmentBuilder()->withSlug('legumes')->withName('Légumes'), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), $this->plain);
        $recipe = new RecipeBuilder()->withName('Hachis')->withServing(4)->withAuthor($this->plain)
            ->addRow(new RecipeRowBuilder()->withIngredient($steak)->withUnit(new UnitBuilder()->isGramme())->withQuantity(500))
            ->addRow(new RecipeRowBuilder()->withIngredient($potato)->withUnit(new UnitBuilder()->isGramme())->withQuantity(800))
            ->build();
        $this->persister->saveRecipe($recipe);
        $main = $this->createdMainList($this->plain);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $this->plain);

        // Then : 200, la liste principale seule ; un repas sans groupe qui a posé toute la recette
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([['id' => $main, 'name' => $this->nameOf($main), 'typeId' => null]], $this->jsonBody()['lists']);
        $this->assertEqualsWithDelta([$steak->getSlug() => 500.0, $potato->getSlug() => 0.8], $this->rows($main), 1e-9);
        $this->assertNull($this->groupOf($main));
        $this->assertNull($this->placedOf($main));
        $this->assertSame(1, $this->listCount($this->plain));

        // When : changer les personnes puis retirer la recette, comme avant
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($main) . '/serving', $this->plain, ['serving' => 2]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$steak->getSlug() => 250.0, $potato->getSlug() => 0.4], $this->rows($main), 1e-9);
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $recipe->getId(), $this->plain);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[], 0], [$this->rows($main), $this->mealCount($main)]);
    }

    public function testARecipeAlreadyInOneOfTheVisitedListsIsRefusedEverywhereAndNothingIsWritten(): void
    {
        // Given : la recette est déjà dans le Marché (planifiée seule, par exemple), la liste principale et la Boucherie sont libres
        $main = $this->createdMainList($this->owner);
        $market = $this->createdList($this->owner, $this->marketId);
        $this->entityManager->getConnection()->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('old-meal', ?, ?, 2)", [$market, $this->recipe->getId()]);
        $before = $this->snapshot([$main, $market]);
        $listsBefore = $this->listCount($this->owner);

        // When
        $this->planned($this->owner);

        // Then : le conflit d'aujourd'hui, ni ligne, ni repas, ni liste de plus (la Boucherie n'a pas été ouverte)
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->snapshot([$main, $market]));
        $this->assertSame($listsBefore, $this->listCount($this->owner));
    }

    public function testPlanningTwiceIsRefusedTheSecondTime(): void
    {
        // Given
        [$main, $butcher, $market] = $this->plannedEverywhere();
        $before = $this->snapshot([$main, $butcher, $market]);

        // When
        $this->planned($this->owner);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->snapshot([$main, $butcher, $market]));
    }

    public function testTheMainListIsNeverOpenedSilently(): void
    {
        // Given : aucune liste en cours
        $listsBefore = $this->listCount($this->owner);

        // When
        $this->planned($this->owner);

        // Then : 404 comme avant, aucune liste ouverte (ni Boucherie, ni Marché)
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame($listsBefore, $this->listCount($this->owner));
    }

    public function testIfMatchIsJudgedOnTheMainListOnly(): void
    {
        // Given : la liste principale en cours, à la version 1
        $main = $this->createdMainList($this->owner);

        // When : une version périmée de la liste principale
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $this->owner, headers: ['HTTP_IF_MATCH' => '"9"']);

        // Then : 412, aucune liste de type ouverte
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->listCount($this->owner));
        $this->assertSame([], $this->rows($main));

        // When : la bonne version passe
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $this->owner, headers: ['HTTP_IF_MATCH' => '"1"']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(3, $this->jsonBody()['lists']);
    }

    public function testAProductGoesToTheListOfItsDestinationAndComesBackOutOfIt(): void
    {
        // Given : la liste principale seule est en cours
        $main = $this->createdMainList($this->owner);

        // When : un produit de la Boucherie, puis un produit sans type
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/increase', $this->owner);
        $steakStatus = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->butter->getSlug() . '/increase', $this->owner);

        // Then : une Boucherie ouverte à la demande, nommée comme le type ; la liste principale a le beurre seulement
        $this->assertSame([204, 204], [$steakStatus, $this->client->getResponse()->getStatusCode()]);
        $this->assertSame(2, $this->listCount($this->owner));
        $this->assertSame([$this->butter->getSlug() => 1.0], $this->rows($main));
        $butcher = $this->entityManager->getConnection()->fetchOne('SELECT id FROM shopping_list WHERE owner = ? AND list_type_id = ?', [$this->owner->getId(), $this->butcherId]);
        $this->assertSame('Boucherie', $this->nameOf($butcher));
        $this->assertSame([$this->steak->getSlug() => 1.0], $this->rows($butcher));
        $this->assertSame('ACTIVE', $this->statusOf($main));

        // When : un deuxième ajout, puis on diminue deux fois
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/increase', $this->owner);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/decrease', $this->owner);
        $afterOne = $this->rows($butcher);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/decrease', $this->owner);

        // Then : la ligne de la Boucherie baisse puis disparaît ; il n'y a toujours que deux listes
        $this->assertSame([$this->steak->getSlug() => 1.0], $afterOne);
        $this->assertSame([], $this->rows($butcher));
        $this->assertSame(2, $this->listCount($this->owner));
    }

    public function testDecreasingFindsTheLineWhereItWasEvenIfTheDestinationChanged(): void
    {
        // Given : le steak a une ligne dans la Boucherie, puis il est mis à part dans la liste principale
        $this->createdMainList($this->owner);
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/increase', $this->owner);
        $butcher = $this->entityManager->getConnection()->fetchOne('SELECT id FROM shopping_list WHERE owner = ? AND list_type_id = ?', [$this->owner->getId(), $this->butcherId]);
        $this->request('PUT', '/api/v1/ingredients/' . $this->steak->getSlug() . '/list-destination', $this->owner, ['destination' => 'main']);

        // When
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/' . $this->steak->getSlug() . '/decrease', $this->owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->rows($butcher));
    }

    public function testTheListsAndTheGroupOfAnotherAccountAreNeverTouched(): void
    {
        // Given : l'autre compte a une liste en cours qui porte un repas du même groupe forgé
        [$main, $butcher] = $this->plannedEverywhere();
        $theirs = $this->createdMainList($this->plain);
        $group = $this->groupOf($butcher);
        $this->entityManager->getConnection()->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving, planned_group_id) VALUES ('forged', ?, 'recipe-x', 3, ?)", [$theirs, $group]);

        // When : le propriétaire change les personnes
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($main) . '/serving', $this->owner, ['serving' => 8]);

        // Then : les listes du propriétaire ont changé, l'autre compte n'a pas été atteint par la valeur de ses lignes
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(3, (int) $this->entityManager->getConnection()->fetchOne("SELECT serving FROM meal WHERE id = 'forged'"));
        $this->assertSame(8, $this->servingOf($butcher));
    }

    /**
     * @return array{string, string, string} la liste principale, la Boucherie et le Marché, la recette planifiée pour 4 personnes
     */
    private function plannedEverywhere(): array
    {
        $main = $this->createdMainList($this->owner);
        $this->planned($this->owner);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lists = $this->jsonBody()['lists'];

        return [$main, $lists[1]['id'], $lists[2]['id']];
    }

    private function planned(User $user): void
    {
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->recipe->getId(), $user);
    }

    private function createdMainList(User $user): string
    {
        return $this->createdList($user, null);
    }

    private function createdList(User $user, ?string $typeId): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $user, $typeId === null ? null : ['typeId' => $typeId]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @param list<string> $departmentSlugs
     */
    private function createdType(User $user, string $name, array $departmentSlugs): string
    {
        $this->request('POST', '/api/v1/shopping-list-types', $user, ['name' => $name]);
        $id = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $user, ['name' => $name, 'departmentSlugs' => $departmentSlugs]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        return $id;
    }

    private function savedIngredient(string $name, DepartmentBuilder $department, UnitBuilder $cooking, UnitBuilder $purchase, ?User $creator = null): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit($cooking)->withDefaultPurchaseUnit($purchase)->withCreator($creator ?? $this->owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedRecipe(User $author): Recipe
    {
        $recipe = new RecipeBuilder()->withName('Hachis parmentier')->withServing(4)->withAuthor($author)
            ->addRow(new RecipeRowBuilder()->withIngredient($this->steak)->withUnit(new UnitBuilder()->isGramme())->withQuantity(500))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->potato)->withUnit(new UnitBuilder()->isGramme())->withQuantity(800))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->onion)->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->butter)->withUnit(new UnitBuilder()->isGramme())->withQuantity(50))
            ->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * Les lignes d'une liste : `slug => quantité`, lues en base.
     *
     * @return array<string, float>
     */
    private function rows(string $listId): array
    {
        $this->entityManager->clear();
        $rows = array_map('floatval', $this->entityManager->getConnection()->fetchAllKeyValue('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]));
        ksort($rows);

        return $rows;
    }

    private function mealOf(string $listId): string
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT id FROM meal WHERE shopping_list = ?', [$listId]);
    }

    private function mealCount(string $listId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM meal WHERE shopping_list = ?', [$listId]);
    }

    private function servingOf(string $listId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT serving FROM meal WHERE shopping_list = ?', [$listId]);
    }

    private function groupOf(string $listId): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT planned_group_id FROM meal WHERE shopping_list = ?', [$listId]);

        return $value === false ? null : $value;
    }

    /**
     * @return list<string>|null
     */
    private function placedOf(string $listId): ?array
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT placed_ingredients FROM meal WHERE shopping_list = ?', [$listId]);

        return $value === null || $value === false ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function statusOf(string $listId): string
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT status FROM shopping_list WHERE id = ?', [$listId]);
    }

    private function nameOf(string $listId): string
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT name, created_at FROM shopping_list WHERE id = ?', [$listId]);

        return $row['name'] ?? (string) new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, \IntlDateFormatter::GREGORIAN, 'dd MMMM yyyy')->format(new \DateTimeImmutable($row['created_at']));
    }

    private function listCount(User $user): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shopping_list WHERE owner = ?', [$user->getId()]);
    }

    /**
     * Tout ce qu'une écriture pourrait changer dans ces listes : statut, version, repas, lignes.
     *
     * @param list<string> $listIds
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $listIds): array
    {
        $connection = $this->entityManager->getConnection();
        $snapshot = [];
        foreach ($listIds as $id) {
            $snapshot[$id] = [
                $connection->fetchAssociative('SELECT status, version, list_type_id FROM shopping_list WHERE id = ?', [$id]),
                $connection->fetchAllAssociative('SELECT id, recipe, serving, planned_group_id, placed_ingredients FROM meal WHERE shopping_list = ? ORDER BY id', [$id]),
                $connection->fetchAllAssociative('SELECT ingredient, quantity, unit FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$id]),
            ];
        }

        return $snapshot;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
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
}

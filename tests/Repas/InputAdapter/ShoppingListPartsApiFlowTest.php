<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
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
 * Le total du parent dans la liste de courses (ADR 0038 décisions 6 à 17, SUB-003) de bout en bout : vrais bus, vraie base, vraie sécurité, vrais
 * lecteurs. « Jaune d'œuf » et « Blanc d'œuf » sont des PARTIES d'« Œuf » (ratio 1) : 3 œufs + 2 jaunes + 2 blancs font 5 œufs, jamais une ligne de
 * jaune ou de blanc. Le drive lit la même ligne « Œuf 5 », sans aucun changement de code.
 */
class ShoppingListPartsApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private Ingredient $egg;
    private Ingredient $yolk;
    private Ingredient $white;
    private Ingredient $flour;
    /** @var array<string, Recipe> */
    private array $recipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->egg = $this->saved(new IngredientBuilder()->isEgg()->withCreator($this->owner)->build());
        $this->yolk = $this->saved(new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('jaune')->withCreator($this->owner)->withParent($this->egg->getSlug(), 1.0)->build());
        $this->white = $this->saved(new IngredientBuilder()->isEgg()->withName("Blanc d'œuf")->withSlug('blanc')->withCreator($this->owner)->withParent($this->egg->getSlug(), 1.0)->build());
        $this->flour = $this->saved(new IngredientBuilder()->isFlour()->withCreator($this->owner)->build());
        $this->recipes['omelette'] = $this->recipe('Omelette', [$this->egg, 3]);
        $this->recipes['mousse'] = $this->recipe('Mousse', [$this->yolk, 2]);
        $this->recipes['meringue'] = $this->recipe('Meringue', [$this->white, 2]);
        $this->recipes['creme'] = $this->recipe('Crème', [$this->yolk, 3]);
        $this->recipes['pain'] = $this->recipe('Pain', [$this->flour, 500]);
    }

    public function testThreeEggsTwoYolksAndTwoWhitesMakeFiveEggsAndNoPartIsALine(): void
    {
        // Given : une liste en cours
        $list = $this->createdList();

        // When : trois recettes, dans l'ordre de la demande du propriétaire
        $this->plan('omelette');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->plan('mousse');
        $this->plan('meringue');

        // Then : « Œuf 5 » en base comme dans la liste, et rien de plus
        $this->assertEqualsWithDelta(['oeuf' => 5.0], $this->rows($list), 1e-9);
        $this->assertSame(['blanc', 'jaune'], array_keys($this->registry($list)));
        $this->assertEqualsWithDelta(['parent' => 'oeuf', 'quantity' => 2.0, 'equivalent' => 2.0], $this->registry($list)['jaune'], 1e-9);
        $detail = $this->detail($list);
        $this->assertSame(1, $detail['rowCount']);
        $row = $detail['departments'][0]['rows'][0];
        $this->assertSame(['id', 'ingredientName', 'quantity', 'unitSymbol', 'checked', 'ingredientSlug', 'unitSlug', 'departmentSlug', 'departmentName', 'includes'], array_keys($row));
        $this->assertEquals(['Œuf', 5, 'oeuf'], [$row['ingredientName'], $row['quantity'], $row['ingredientSlug']]);
        // And : la ligne dit ce qu'elle contient (quantité de la partie, triées par nom), jamais l'équivalent
        $this->assertSame([['name' => "Blanc d'œuf", 'quantity' => 2, 'unitSymbol' => ''], ['name' => "Jaune d'œuf", 'quantity' => 2, 'unitSymbol' => '']], $row['includes']);
    }

    public function testTwoWhitesThenThreeYolksMakeThreeEggsAndNeverFive(): void
    {
        // Given
        $list = $this->createdList();

        // When
        $this->plan('meringue');
        $this->assertEqualsWithDelta(['oeuf' => 2.0], $this->rows($list), 1e-9);
        $this->plan('creme');

        // Then
        $this->assertEqualsWithDelta(['oeuf' => 3.0], $this->rows($list), 1e-9);
    }

    public function testRemovingARecipeTakesBackItsShareOfTheTotal(): void
    {
        // Given : 3 œufs + 3 jaunes + 2 blancs
        $list = $this->createdList();
        $this->plan('omelette');
        $this->plan('creme');
        $this->plan('meringue');
        $this->assertEqualsWithDelta(['oeuf' => 6.0], $this->rows($list), 1e-9);

        // When / Then
        $this->unplan('omelette');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta(['oeuf' => 3.0], $this->rows($list), 1e-9);
        $this->unplan('creme');
        $this->assertEqualsWithDelta(['oeuf' => 2.0], $this->rows($list), 1e-9);
        $this->assertSame(['blanc'], array_keys($this->registry($list)));
        $this->unplan('meringue');
        $this->assertSame([[], []], [$this->rows($list), $this->registry($list)]);
        $this->assertSame(0, $this->mealCount($list));
    }

    public function testChangingThePeopleOfAMealMovesThePartAndTheTotal(): void
    {
        // Given : 3 œufs + 2 jaunes pour 4 personnes
        $list = $this->createdList();
        $this->plan('omelette');
        $this->plan('mousse');
        $mealId = $this->mealOfRecipe('mousse');

        // When : la mousse passe à 8 personnes, puis à 2
        $this->request('PUT', "/api/v1/meals/$mealId/serving", $this->owner, ['serving' => 8]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta(['oeuf' => 7.0], $this->rows($list), 1e-9);
        $this->assertEqualsWithDelta(4.0, $this->registry($list)['jaune']['quantity'], 1e-9);
        $this->request('PUT', "/api/v1/meals/$mealId/serving", $this->owner, ['serving' => 2]);

        // Then : 1 jaune, donc 3 + 1 = 4
        $this->assertEqualsWithDelta(['oeuf' => 4.0], $this->rows($list), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->registry($list)['jaune']['equivalent'], 1e-9);
    }

    public function testAGestureWithPartsRaisesTheVersionOfTheListOnce(): void
    {
        // Given
        $list = $this->createdList();
        $this->plan('omelette');
        $before = $this->detail($list)['version'];

        // When : une recette de parties
        $this->plan('mousse');

        // Then : une écriture de la liste, une version de plus (le registre fait partie de l'agrégat)
        $this->assertSame($before + 1, $this->detail($list)['version']);
    }

    public function testARecipeSplitBetweenTwoListsKeepsThePartsWithTheirParent(): void
    {
        // Given : le type « Marché » tient le rayon divers (l'œuf et ses parties) ; la farine, d'un autre rayon, reste dans la liste principale ; le
        // choix de liste du jaune est un détail qu'une partie ignore
        $main = $this->createdList();
        $marketId = $this->createdType('Marché', ['divers']);
        $this->entityManager->getConnection()->executeStatement("UPDATE ingredient SET list_destination = 'main' WHERE slug = 'jaune'");
        $gateau = $this->recipe('Gâteau', [$this->egg, 1], [$this->yolk, 2], [$this->flour, 500]);
        $this->recipes['gateau'] = $gateau;

        // When
        $this->plan('gateau');

        // Then : 200 et deux listes ; « Œuf 3 » (1 + 2 jaunes) est dans le Marché avec ses parties, la farine dans la liste principale
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lists = $this->jsonBody()['lists'];
        $this->assertSame([$main, $marketId], [$lists[0]['id'], $lists[1]['typeId'] === $marketId ? $marketId : null]);
        $market = $lists[1]['id'];
        $this->assertEqualsWithDelta(['farine' => 0.5], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta(['oeuf' => 3.0], $this->rows($market), 1e-9);
        $this->assertSame([], $this->registry($main));
        $this->assertSame(['jaune'], array_keys($this->registry($market)));
        $this->assertEqualsCanonicalizing(['oeuf', 'jaune'], $this->placedOf($market));
        $this->assertSame(['farine'], $this->placedOf($main));

        // When : 8 personnes depuis le repas de la liste principale, puis on retire la recette
        $this->request('PUT', '/api/v1/meals/' . $this->mealOfRecipe('gateau', $main) . '/serving', $this->owner, ['serving' => 8]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : le groupe a doublé
        $this->assertEqualsWithDelta(['farine' => 1.0], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta(['oeuf' => 6.0], $this->rows($market), 1e-9);
        $this->unplan('gateau');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[], [], [], []], [$this->rows($main), $this->rows($market), $this->registry($main), $this->registry($market)]);
    }

    public function testACheckedLineStaysCheckedWhenAPartMakesItGrow(): void
    {
        // Given : 3 œufs et de la farine, la ligne des œufs est cochée (la farine reste à acheter : cocher ne termine pas la liste)
        $list = $this->createdList();
        $this->plan('omelette');
        $this->plan('pain');
        $this->request('POST', '/api/v1/shopping-list-rows/' . $this->rowIdOf($list, 'oeuf') . '/tick', $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : une recette de jaunes la fait grandir
        $this->plan('mousse');

        // Then : « Œuf 5 », toujours cochée (une quantité qui augmente ne décoche rien)
        $rows = array_merge(...array_column($this->detail($list)['departments'], 'rows'));
        $row = array_values(array_filter($rows, static fn(array $row): bool => $row['ingredientSlug'] === 'oeuf'))[0];
        $this->assertEquals(['Œuf', 5, true], [$row['ingredientName'], $row['quantity'], $row['checked']]);
    }

    public function testAPartAddedByHandIsFoldedIntoItsParentThenTakenBack(): void
    {
        // Given : 3 œufs
        $list = $this->createdList();
        $this->plan('omelette');

        // When : « ajouter » le jaune deux fois depuis le sélecteur de produits
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/jaune/increase', $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/jaune/increase', $this->owner);

        // Then : 5 œufs, aucune ligne de jaune
        $this->assertEqualsWithDelta(['oeuf' => 5.0], $this->rows($list), 1e-9);
        $this->assertSame(
            [['name' => "Jaune d'œuf", 'quantity' => 2, 'unitSymbol' => '']],
            $this->detail($list)['departments'][0]['rows'][0]['includes'],
        );

        // When : on en retire deux
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/jaune/decrease', $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/jaune/decrease', $this->owner);

        // Then : les 3 œufs de la recette restent
        $this->assertEqualsWithDelta(['oeuf' => 3.0], $this->rows($list), 1e-9);
        $this->assertSame([], $this->registry($list));
        $this->assertSame([], $this->detail($list)['departments'][0]['rows'][0]['includes']);
    }

    public function testASentListIsNotModifiedByAnyGestureAndTheBasketLinesDoNotTouchTheRegistry(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs, la liste part au drive
        $list = $this->createdList();
        $this->plan('omelette');
        $this->plan('mousse');
        $this->plan('meringue');
        $mealId = $this->mealOfRecipe('mousse');
        $this->request('POST', "/api/v1/shopping-lists/$list/send", $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $before = $this->snapshot($list);

        // When / Then : changer les personnes est refusé (409), retirer et ajouter n'ont plus de liste en cours (404)
        $this->request('PUT', "/api/v1/meals/$mealId/serving", $this->owner, ['serving' => 8]);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame('SHOPPING_LIST_SENT', $this->jsonBody()['error']['message']);
        $this->unplan('mousse');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->plan('creme');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/shopping-lists/active/ingredients/jaune/increase', $this->owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->snapshot($list));

        // When : le drive met les œufs au panier
        $this->request('POST', "/api/v1/drive/plans/$list/basket-lines", $this->owner, ['ingredientSlugs' => ['oeuf']]);

        // Then : la ligne est cochée, le registre et les quantités n'ont pas bougé, la version non plus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $after = $this->snapshot($list);
        $this->assertSame($before['registry'], $after['registry']);
        $this->assertSame($before['version'], $after['version']);
        $this->assertSame([['oeuf', '5', true]], array_map(static fn(array $row): array => [$row['ingredient'], rtrim(rtrim((string) $row['quantity'], '0'), '.'), (bool) $row['checked']], $after['rows']));
    }

    public function testThePlanAndTheEstimateOfTheDriveReadTheTotalOfTheParentAndNeverAPart(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs, la liste est envoyée au drive
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($this->owner->getId())->build());
        $list = $this->createdList();
        $this->plan('omelette');
        $this->plan('mousse');
        $this->plan('meringue');
        $this->request('POST', "/api/v1/shopping-lists/$list/send", $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('GET', "/api/v1/drive/plans/$list", $this->owner);

        // Then : une seule ligne, « Œuf », 5 ; ni « Jaune d'œuf » ni « Blanc d'œuf » (aucun changement de code du contexte Drive)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lines = $this->jsonBody()['lines'];
        $this->assertSame(['oeuf'], array_column($lines, 'ingredientSlug'));
        $this->assertSame(['Œuf'], array_column($lines, 'ingredientName'));
        $this->assertEqualsWithDelta(5.0, $lines[0]['needed']['quantity'], 1e-9);

        // When : l'estimation
        $this->request('GET', "/api/v1/drive/estimates/$list", $this->owner);

        // Then : la même ligne
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['oeuf'], array_column($this->jsonBody()['lines'], 'ingredientSlug'));
    }

    // --- Aides ---

    private function saved(Ingredient $ingredient): Ingredient
    {
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * Une recette pour quatre personnes : des paires [ingrédient, quantité] (la farine en grammes, le reste à l'unité).
     *
     * @param array{Ingredient, int} ...$rows
     */
    private function recipe(string $name, array ...$rows): Recipe
    {
        $builder = new RecipeBuilder()->withName($name)->withServing(4)->withAuthor($this->owner);
        foreach ($rows as [$ingredient, $quantity]) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($ingredient->getSlug() === $this->flour->getSlug() ? new UnitBuilder()->isGramme() : new UnitBuilder()->isUnite())->withQuantity($quantity));
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function createdList(): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $this->owner);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @param list<string> $departmentSlugs
     */
    private function createdType(string $name, array $departmentSlugs): string
    {
        $this->request('POST', '/api/v1/shopping-list-types', $this->owner, ['name' => $name]);
        $id = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $this->owner, ['name' => $name, 'departmentSlugs' => $departmentSlugs]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        return $id;
    }

    private function plan(string $key): void
    {
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->recipes[$key]->getId(), $this->owner);
    }

    private function unplan(string $key): void
    {
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->recipes[$key]->getId(), $this->owner);
    }

    /**
     * @return array<string, float>
     */
    private function rows(string $listId): array
    {
        $this->entityManager->clear();
        $rows = array_map('floatval', $this->entityManager->getConnection()->fetchAllKeyValue('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]));
        ksort($rows);

        return $rows;
    }

    /**
     * Le registre caché des parties de la liste, lu en base.
     *
     * @return array<string, array{parent: string, quantity: float, equivalent: float}>
     */
    private function registry(string $listId): array
    {
        $json = $this->entityManager->getConnection()->fetchOne('SELECT part_shares FROM shopping_list WHERE id = ?', [$listId]);
        $registry = $json === null || $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        ksort($registry);

        return $registry;
    }

    private function mealCount(string $listId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM meal WHERE shopping_list = ?', [$listId]);
    }

    private function mealOfRecipe(string $key, ?string $listId = null): string
    {
        $sql = 'SELECT id FROM meal WHERE recipe = ?' . ($listId === null ? '' : ' AND shopping_list = ?');

        return $this->entityManager->getConnection()->fetchOne($sql, $listId === null ? [$this->recipes[$key]->getId()] : [$this->recipes[$key]->getId(), $listId]);
    }

    /**
     * @return list<string>
     */
    private function placedOf(string $listId): array
    {
        return json_decode($this->entityManager->getConnection()->fetchOne('SELECT placed_ingredients FROM meal WHERE shopping_list = ?', [$listId]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rowIdOf(string $listId, string $slug): string
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT id FROM shopping_list_row WHERE shopping_list = ? AND ingredient = ?', [$listId, $slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $listId): array
    {
        $this->request('GET', "/api/v1/shopping-lists/$listId", $this->owner);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->jsonBody();
    }

    /**
     * Tout ce qu'une écriture pourrait changer dans cette liste : statut, version, registre, lignes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $listId): array
    {
        $connection = $this->entityManager->getConnection();
        $list = $connection->fetchAssociative('SELECT status, version FROM shopping_list WHERE id = ?', [$listId]);

        return [
            'status' => $list['status'],
            'version' => (int) $list['version'],
            'registry' => $this->registry($listId),
            'rows' => $connection->fetchAllAssociative('SELECT ingredient, quantity, checked FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]),
            'meals' => $connection->fetchAllAssociative('SELECT recipe, serving FROM meal WHERE shopping_list = ? ORDER BY recipe', [$listId]),
        ];
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
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
}

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
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
 * Planifier, changer les personnes et retirer une recette qui en utilise une autre (ADR 0040 décisions 11 à 16, COMP-004) de bout en bout : vrais bus, vraie
 * base, vraie sécurité. Aucune route n'écrit encore de composant (COMP-002) : ils sont posés par le dépôt. Pour un compte de 4 personnes :
 *
 *   - « Pâte sablée » pour 6 : Farine 250 g (rayon boulangerie, achetée au kilo), Beurre 125 g (rayon divers, acheté au gramme) ;
 *   - « Tarte au citron » pour 4 : Sucre 100 g (rayon boulangerie, acheté au kilo), Œuf 2 (rayon divers) ; elle utilise la pâte une fois.
 *
 * Les lignes de la liste d'un repas de 4 personnes : 0,25 kg de farine, 0,1 kg de sucre, 125 g de beurre, 2 œufs.
 */
class ComposedRecipePlanningApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $other;
    private Recipe $dough;
    private Recipe $pie;
    private Ingredient $flour;
    private Ingredient $sugar;
    private Ingredient $butter;
    private Ingredient $egg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->owner = $this->savedUser();
        $this->other = $this->savedUser();

        [$this->flour, $this->sugar, $this->butter, $this->egg] = $this->ingredientsOf($this->owner);
        [$this->dough, $this->pie] = $this->recipesOf($this->owner, $this->flour, $this->sugar, $this->butter, $this->egg);
    }

    public function testAComposedRecipeIsPlannedChangedAndRemovedWithTheLinesOfItsUsedRecipe(): void
    {
        // Given
        $list = $this->createdList($this->owner);

        // When : planifier la tarte
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : 200, la liste principale seule, les lignes de la tarte ET de sa pâte, un seul repas (celui de la tarte)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$list], array_column($this->jsonBody()['lists'], 'id'));
        $this->assertEqualsWithDelta($this->expected(1.0), $this->rows($list), 1e-9);
        $this->assertSame([$this->pie->getId()], $this->mealRecipes($list));
        $this->assertNull($this->groupOf($list));
        $this->assertNull($this->placedOf($list), 'un repas qui pose toute la recette ne garde aucune répartition');

        // When : 8 personnes (le double)
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($list) . '/serving', $this->owner, ['serving' => 8]);

        // Then : les deux niveaux ont doublé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta($this->expected(2.0), $this->rows($list), 1e-9);

        // When : 2 personnes (la moitié) puis de nouveau 4
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($list) . '/serving', $this->owner, ['serving' => 2]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta($this->expected(0.5), $this->rows($list), 1e-9);
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($list) . '/serving', $this->owner, ['serving' => 4]);
        $this->assertEqualsWithDelta($this->expected(1.0), $this->rows($list), 1e-9);

        // When : on retire la tarte
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : plus de repas, plus de ligne
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[], 0], [$this->rows($list), $this->mealCount($list)]);
    }

    public function testThePastryAloneAndThePieAddUpAndEachRemovalDeductsOnlyItsShare(): void
    {
        // Given : la pâte seule pour 4 (écrite pour 6 : k = 4/6)
        $list = $this->createdList($this->owner);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->dough->getId(), $this->owner);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$this->flour->getSlug() => 250 / 6 * 4 / 1000, $this->butter->getSlug() => 125 / 6 * 4], $this->rows($list), 1e-9);

        // When : puis la tarte, qui l'utilise (planifier la pâte seule ET son parent est permis)
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : les quantités s'additionnent, deux repas
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta(
            [
                $this->flour->getSlug() => 250 / 6 * 4 / 1000 + 0.25,
                $this->sugar->getSlug() => 0.1,
                $this->butter->getSlug() => 125 / 6 * 4 + 125.0,
                $this->egg->getSlug() => 2.0,
            ],
            $this->rows($list),
            1e-9,
        );
        $this->assertEqualsCanonicalizing([$this->dough->getId(), $this->pie->getId()], $this->mealRecipes($list));

        // When : on retire la tarte
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : il reste la pâte seule, comme avant la tarte
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$this->flour->getSlug() => 250 / 6 * 4 / 1000, $this->butter->getSlug() => 125 / 6 * 4], $this->rows($list), 1e-9);

        // When : on retire la pâte
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->dough->getId(), $this->owner);

        // Then
        $this->assertSame([[], 0], [$this->rows($list), $this->mealCount($list)]);
    }

    public function testARecipeSplitBetweenTwoListsSplitsTheLinesOfItsUsedRecipeToo(): void
    {
        // Given : le type « Boulangerie » tient le rayon de la farine (la pâte) et du sucre (la tarte)
        $main = $this->createdList($this->owner);
        $bakeryType = $this->createdType($this->owner, 'Boulangerie', ['boulangerie']);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : la liste principale d'abord, puis la Boulangerie, ouverte à la demande
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lists = $this->jsonBody()['lists'];
        $this->assertSame([$main, null], [$lists[0]['id'], $lists[0]['typeId']]);
        $this->assertSame(['Boulangerie', $bakeryType], [$lists[1]['name'], $lists[1]['typeId']]);
        $bakery = $lists[1]['id'];

        // And : la farine de la PÂTE est allée en boulangerie avec le sucre de la tarte ; le beurre et les œufs restent dans la liste principale
        $this->assertEqualsWithDelta([$this->butter->getSlug() => 125.0, $this->egg->getSlug() => 2.0], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta([$this->flour->getSlug() => 0.25, $this->sugar->getSlug() => 0.1], $this->rows($bakery), 1e-9);
        $this->assertEqualsCanonicalizing([$this->flour->getSlug(), $this->sugar->getSlug()], $this->placedOf($bakery));
        $this->assertEqualsCanonicalizing([$this->butter->getSlug(), $this->egg->getSlug()], $this->placedOf($main));
        $this->assertSame($this->groupOf($main), $this->groupOf($bakery));
        $this->assertNotNull($this->groupOf($main));

        // When : on passe à 8 personnes depuis le repas de la Boulangerie
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($bakery) . '/serving', $this->owner, ['serving' => 8]);

        // Then : le groupe entier a doublé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$this->butter->getSlug() => 250.0, $this->egg->getSlug() => 4.0], $this->rows($main), 1e-9);
        $this->assertEqualsWithDelta([$this->flour->getSlug() => 0.5, $this->sugar->getSlug() => 0.2], $this->rows($bakery), 1e-9);

        // When : on retire la tarte
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);

        // Then : plus rien nulle part
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[], 0, [], 0], [$this->rows($main), $this->mealCount($main), $this->rows($bakery), $this->mealCount($bakery)]);
    }

    public function testTwoAccountsNeverShareTheLinesOfTheirComposedRecipes(): void
    {
        // Given : un autre compte, ses propres ingrédients, sa propre tarte et sa propre pâte, sa liste en cours
        [$flour, $sugar, $butter, $egg] = $this->ingredientsOf($this->other);
        [, $theirPie] = $this->recipesOf($this->other, $flour, $sugar, $butter, $egg);
        $mine = $this->createdList($this->owner);
        $theirs = $this->createdList($this->other);

        // When : les deux planifient leur tarte, l'autre pour 8 personnes
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $theirPie->getId(), $this->other);
        $this->request('PUT', '/api/v1/meals/' . $this->mealOf($theirs) . '/serving', $this->other, ['serving' => 8]);

        // Then : chacun ses lignes, ses quantités, sans mélange
        $this->assertEqualsWithDelta($this->expected(1.0), $this->rows($mine), 1e-9);
        $this->assertEqualsWithDelta([$flour->getSlug() => 0.5, $sugar->getSlug() => 0.2, $butter->getSlug() => 250.0, $egg->getSlug() => 4.0], $this->rows($theirs), 1e-9);

        // When : l'autre compte planifie SA tarte alors que la mienne est composée avec MA pâte : la recette d'un autre compte n'est pas planifiable
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->other);

        // Then : 404 comme avant, rien n'a bougé chez lui
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$flour->getSlug() => 0.5, $sugar->getSlug() => 0.2, $butter->getSlug() => 250.0, $egg->getSlug() => 4.0], $this->rows($theirs), 1e-9);
    }

    public function testAMissingConversionInTheUsedRecipeIsRefusedWithTheUsualErrorAndNothingIsWritten(): void
    {
        // Given : le beurre de la pâte est acheté en plaquettes, sans aucune conversion vers le gramme
        $unconvertible = new IngredientBuilder()->withName('Beurre en plaquette')->withDepartment(new DepartmentBuilder()->isMiscellaneous())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isBlock())->withCreator($this->owner, true)->build();
        $this->persister->saveIngredient($unconvertible);
        $dough = $this->recipe($this->owner, 'Pâte à la plaquette', 6, [[$unconvertible, 125]]);
        $pie = $this->recipe($this->owner, 'Tarte à la plaquette', 4, [[$this->sugar, 100]], [$dough]);
        $list = $this->createdList($this->owner);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $pie->getId(), $this->owner);

        // Then : 422 et le message d'avant (la route ne nomme pas la recette), ni ligne ni repas
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('No conversion path found', $this->jsonBody()['error']['message']);
        $this->assertSame([[], 0], [$this->rows($list), $this->mealCount($list)]);
    }

    public function testTheDriveReadsPlainIngredientLinesAsBefore(): void
    {
        // Given : la tarte est planifiée, la liste part au drive
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($this->owner->getId())->build());
        $list = $this->createdList($this->owner);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $this->pie->getId(), $this->owner);
        $this->request('POST', "/api/v1/shopping-lists/$list/send", $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('GET', "/api/v1/drive/plans/$list", $this->owner);

        // Then : une ligne par ingrédient, comme pour une recette sans composant, ni tarte ni pâte
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lines = $this->jsonBody()['lines'];
        $slugs = array_column($lines, 'ingredientSlug');
        sort($slugs);
        $expectedSlugs = [$this->butter->getSlug(), $this->egg->getSlug(), $this->flour->getSlug(), $this->sugar->getSlug()];
        sort($expectedSlugs);
        $this->assertSame($expectedSlugs, $slugs);
        $eggs = array_values(array_filter($lines, fn(array $line): bool => $line['ingredientSlug'] === $this->egg->getSlug()))[0];
        $this->assertEqualsWithDelta(2.0, $eggs['needed']['quantity'], 1e-9);

        // When : l'estimation
        $this->request('GET', "/api/v1/drive/estimates/$list", $this->owner);

        // Then : les mêmes lignes
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $estimated = array_column($this->jsonBody()['lines'], 'ingredientSlug');
        sort($estimated);
        $this->assertSame($slugs, $estimated);
    }

    // --- Aides ---

    /**
     * Les quantités de la liste pour un repas de 4 personnes multiplié par `$factor`, la pâte « 1 fois ».
     *
     * @return array<string, float>
     */
    private function expected(float $factor): array
    {
        return [
            $this->flour->getSlug() => 0.25 * $factor,
            $this->sugar->getSlug() => 0.1 * $factor,
            $this->butter->getSlug() => 125.0 * $factor,
            $this->egg->getSlug() => 2.0 * $factor,
        ];
    }

    /**
     * Farine et sucre (rayon boulangerie, au gramme, achetés au kilo), beurre (gramme) et œuf (unité) de cet utilisateur.
     *
     * @return array{Ingredient, Ingredient, Ingredient, Ingredient}
     */
    private function ingredientsOf(User $owner): array
    {
        $bakery = static fn() => new DepartmentBuilder()->isBakery();
        $misc = static fn() => new DepartmentBuilder()->isMiscellaneous();
        $make = function (string $name, DepartmentBuilder $department, UnitBuilder $cooking, UnitBuilder $purchase) use ($owner): Ingredient {
            $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit($cooking)->withDefaultPurchaseUnit($purchase)->withCreator($owner, true)->build();
            $this->persister->saveIngredient($ingredient);

            return $ingredient;
        };

        return [
            $make('Farine', $bakery(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo()),
            $make('Sucre', $bakery(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo()),
            $make('Beurre', $misc(), new UnitBuilder()->isGramme(), new UnitBuilder()->isGramme()),
            $make('Œuf', $misc(), new UnitBuilder()->isUnite(), new UnitBuilder()->isUnite()),
        ];
    }

    /**
     * @return array{Recipe, Recipe} la pâte puis la tarte qui l'utilise une fois
     */
    private function recipesOf(User $owner, Ingredient $flour, Ingredient $sugar, Ingredient $butter, Ingredient $egg): array
    {
        $dough = $this->recipe($owner, 'Pâte sablée', 6, [[$flour, 250], [$butter, 125]]);
        $pie = $this->recipe($owner, 'Tarte au citron', 4, [[$sugar, 100], [$egg, 2]], [$dough]);

        return [$dough, $pie];
    }

    /**
     * @param list<array{Ingredient, int}> $rows les lignes : l'ingrédient et sa quantité, à l'unité (l'œuf) ou au gramme (le reste)
     * @param list<Recipe> $uses les recettes utilisées, une fois chacune
     */
    private function recipe(User $author, string $name, int $serving, array $rows, array $uses = []): Recipe
    {
        $builder = new RecipeBuilder()->withName($name)->withServing($serving)->withAuthor($author);
        foreach ($rows as [$ingredient, $quantity]) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($ingredient->getDefaultCookingUnit()->getSlug() === 'unite' ? new UnitBuilder()->isUnite() : new UnitBuilder()->isGramme())->withQuantity($quantity));
        }
        foreach ($uses as $used) {
            $builder->withComponent($used, 1.0);
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function createdList(User $user): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $user);
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

    /**
     * @return list<string>
     */
    private function mealRecipes(string $listId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT recipe FROM meal WHERE shopping_list = ? ORDER BY recipe', [$listId]);
    }

    private function mealCount(string $listId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM meal WHERE shopping_list = ?', [$listId]);
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

    private function savedUser(): User
    {
        $user = new UserBuilder()->withServing(4)->build();
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

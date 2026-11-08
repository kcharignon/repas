<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Copier la recette COMPOSÉE d'un autre, de bout en bout (ADR 0040 décisions 33 à 35, COMP-003) : vrais bus, vraie base, listeners et sécurité. L'auteur écrit
 *
 *   - « Pâte sablée » pour 6 : Farine 250 g (achetée au kilo), Beurre 125 g (acheté au gramme) ;
 *   - « Tarte au citron » pour 4 : Sucre 100 g (acheté au kilo), Œuf 2, Farine 50 g ; elle utilise la pâte 2 fois.
 *
 * Pour un compte de 4 personnes, la tarte apporte à la liste : 0,1 kg de sucre, 2 œufs, 0,05 kg de farine ; la pâte (250 g de farine et 125 g de beurre,
 * deux fois), 0,5 kg de farine et 250 g de beurre : au total 0,1 kg de sucre, 2 œufs, 0,55 kg de farine et 250 g de beurre.
 */
class CopyComposedRecipeApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $author;
    private User $copier;
    private User $third;
    private Ingredient $flour;
    private Ingredient $sugar;
    private Ingredient $butter;
    private Ingredient $egg;
    private Recipe $dough;
    private Recipe $tart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->author = $this->savedUser();
        $this->copier = $this->savedUser();
        $this->third = $this->savedUser();

        $this->flour = $this->savedIngredient('Farine', new DepartmentBuilder()->isBakery(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo());
        $this->sugar = $this->savedIngredient('Sucre', new DepartmentBuilder()->isBakery(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo());
        $this->butter = $this->savedIngredient('Beurre', new DepartmentBuilder()->isMiscellaneous(), new UnitBuilder()->isGramme(), new UnitBuilder()->isGramme());
        $this->egg = $this->savedIngredient('Œuf', new DepartmentBuilder()->isMiscellaneous(), new UnitBuilder()->isUnite(), new UnitBuilder()->isUnite());
        $this->dough = $this->savedRecipe('Pâte sablée', 6, [[$this->flour, 250], [$this->butter, 125]]);
        $this->tart = $this->savedRecipe('Tarte au citron', 4, [[$this->sugar, 100], [$this->egg, 2], [$this->flour, 50]], [[$this->dough, 2.0]]);
    }

    public function testACopierGetsTheComposedRecipeAndItsUsedRecipeAndPlansTheCopyWithTheRightLines(): void
    {
        // Given
        $list = $this->createdList($this->copier);

        // When : il copie la tarte
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then : la réponse d'avant (201, Location, pas de corps), la tarte copiée, ordinaire, à lui
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $copyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame([$this->copier->getId(), $this->tart->getId(), false], $this->recipeRow($copyId));

        // And : elle utilise SA copie de la pâte, 2 fois, avec les lignes telles qu'écrites et SES ingrédients (la farine n'a été copiée qu'une fois)
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->copier);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $copy = $this->jsonBody();
        $this->assertCount(1, $copy['components']);
        $component = $copy['components'][0];
        $this->assertSame(['Pâte sablée', 6, 2.0, false, true], [$component['name'], $component['serving'], (float) $component['multiplier'], $component['secret'], $component['convertible']]);
        $this->assertNotSame($this->dough->getId(), $component['recipeId']);
        $this->assertSame([$this->copier->getId(), $this->dough->getId(), false], $this->recipeRow($component['recipeId']));
        $mine = $this->ingredientSlugsOf($this->copier);
        $this->assertEqualsCanonicalizing(['Beurre', 'Farine', 'Sucre', 'Œuf'], array_keys($mine), 'un seul ingrédient par nom, quatre en tout');
        $this->assertEqualsCanonicalizing([$mine['Farine'], $mine['Beurre']], array_column($component['rows'], 'ingredientSlug'));
        $this->assertEqualsCanonicalizing([$mine['Sucre'], $mine['Œuf'], $mine['Farine']], array_column($copy['rows'], 'ingredientSlug'), 'rows reste les seules lignes propres');
        $this->assertEqualsCanonicalizing([250.0, 125.0], array_column($component['rows'], 'quantity'));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->copier->getId()));

        // And : l'original n'a pas bougé, il utilise toujours la pâte de l'auteur
        $this->request('GET', '/api/v1/recipes/' . $this->tart->getId(), $this->author);
        $this->assertSame([$this->dough->getId()], array_column($this->jsonBody()['components'], 'recipeId'));

        // When : il planifie la copie
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $copyId, $this->copier);

        // Then : les lignes aplaties arrivent dans la liste, avec les bonnes quantités et SES ingrédients
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta(
            [$mine['Sucre'] => 0.1, $mine['Œuf'] => 2.0, $mine['Farine'] => 0.55, $mine['Beurre'] => 250.0],
            $this->rows($list),
            1e-9,
        );
        $this->assertSame([$copyId], $this->mealRecipes($list), 'un seul repas : la tarte, jamais la pâte');
    }

    public function testTheCopiedRecipesLeaveTheListOfTheRecipesOfOthers(): void
    {
        // Given : la tarte et la pâte de l'auteur sont proposées à ceux qui n'en ont pas de copie
        $this->request('GET', '/api/v1/recipes/other-people', $this->copier);
        $before = $this->jsonBody();
        $this->assertEqualsCanonicalizing([$this->dough->getId(), $this->tart->getId()], array_column($before['member'], 'id'));

        // When : il copie la tarte
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/recipes/other-people', $this->copier);

        // Then : la pâte n'est plus à copier non plus (il en a la copie), comme n'importe quelle copie ; un autre compte les voit encore
        $this->assertSame([[], 0], [$this->jsonBody()['member'], $this->jsonBody()['totalItems']]);
        $this->request('GET', '/api/v1/recipes/other-people', $this->third);
        $theirs = array_column($this->jsonBody()['member'], 'name');
        $this->assertContains('Tarte au citron', $theirs);
        $this->assertContains('Pâte sablée', $theirs);
    }

    public function testTheCopiesAreIndependentOfTheOriginalsAndCopyingACopyCopiesTheCopiedUsedRecipe(): void
    {
        // Given : le copieur a copié la tarte
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);
        $copyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->copier);
        $doughCopyId = $this->jsonBody()['components'][0]['recipeId'];

        // When : l'auteur change sa pâte (en base) puis supprime sa tarte
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe_row SET quantity = 999 WHERE recipe = ?', [$this->dough->getId()]);
        $this->request('DELETE', '/api/v1/recipes/' . $this->tart->getId(), $this->author);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : la copie du copieur n'a pas bougé et garde ses composants
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->copier);
        $copy = $this->jsonBody();
        $this->assertSame([$doughCopyId], array_column($copy['components'], 'recipeId'));
        $this->assertEqualsCanonicalizing([250.0, 125.0], array_column($copy['components'][0]['rows'], 'quantity'));

        // When : un troisième compte copie la copie du copieur
        $this->request('POST', "/api/v1/recipes/$copyId/copy", $this->third);

        // Then : il a la tarte ET la pâte, qui remontent à celles du copieur (jamais à celles de l'auteur), avec ses ingrédients à lui
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $thirdCopyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame([$this->third->getId(), $copyId, false], $this->recipeRow($thirdCopyId));
        $this->request('GET', "/api/v1/recipes/$thirdCopyId", $this->third);
        $component = $this->jsonBody()['components'][0];
        $this->assertSame([$this->third->getId(), $doughCopyId, false], $this->recipeRow($component['recipeId']));
        $thirds = $this->ingredientSlugsOf($this->third);
        $this->assertEqualsCanonicalizing([$thirds['Farine'], $thirds['Beurre']], array_column($component['rows'], 'ingredientSlug'));
    }

    public function testTheCopiersOwnCopyOfTheUsedRecipeIsReusedAndAnotherOneWithComponentsIsNot(): void
    {
        // Given : il a déjà copié la pâte seule
        $this->request('POST', '/api/v1/recipes/' . $this->dough->getId() . '/copy', $this->copier);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $myDough = basename($this->client->getResponse()->headers->get('Location'));

        // When : il copie la tarte
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then : la tarte utilise SA pâte, rien n'est recopié : deux recettes en tout
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $firstCopy = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('GET', "/api/v1/recipes/$firstCopy", $this->copier);
        $this->assertSame([$myDough], array_column($this->jsonBody()['components'], 'recipeId'));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->copier->getId()));

        // When : sa pâte utilise maintenant une recette à lui (elle ne peut plus servir à une autre), puis il copie de nouveau la tarte
        $own = $this->savedRecipeFor($this->copier, 'Crème à lui', 4, [[$this->ingredientOf($this->copier, 'Sucre'), 100]]);
        $this->entityManager->getConnection()->executeStatement('INSERT INTO recipe_component (id, recipe, component, multiplier, position) VALUES (?, ?, ?, 1, 0)', ['edge-of-my-dough', $myDough, $own->getId()]);
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET component_count = 1 WHERE id = ?', [$myDough]);
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then : une copie neuve de la pâte, la sienne reste composée
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $secondCopy = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('GET', "/api/v1/recipes/$secondCopy", $this->copier);
        $usedId = $this->jsonBody()['components'][0]['recipeId'];
        $this->assertNotSame($myDough, $usedId);
        $this->assertSame([$this->copier->getId(), $this->dough->getId(), 0], [...array_slice($this->recipeRow($usedId), 0, 2), $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $usedId)]);
        $this->assertSame(1, $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $myDough));
    }

    public function testTheAuthorCopyingHisOwnComposedRecipeSharesItsUsedRecipe(): void
    {
        // When
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->author);

        // Then : une recette de plus, qui utilise la MÊME pâte, 2 fois ; aucune pâte recopiée, aucun ingrédient copié
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('GET', "/api/v1/recipes/$copyId", $this->author);
        $components = $this->jsonBody()['components'];
        $this->assertSame([[$this->dough->getId(), 2.0]], array_map(static fn(array $c): array => [$c['recipeId'], (float) $c['multiplier']], $components));
        $this->assertSame(3, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->author->getId()));
        $this->assertSame(4, $this->scalar('SELECT COUNT(*) FROM ingredient'));

        // And : la pâte sert toujours, elle ne se supprime pas
        $this->request('DELETE', '/api/v1/recipes/' . $this->dough->getId(), $this->author);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testTheSecretComposedRecipeOfAnotherIsNotFoundAndTheAuthorCopyOfItIsSecretToo(): void
    {
        // Given : la tarte et la pâte de l'auteur sont secrètes
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET secret = true WHERE id IN (?, ?)', [$this->tart->getId(), $this->dough->getId()]);
        $this->request('POST', '/api/v1/recipes/unknown-recipe-id/copy', $this->copier);
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        // When : un autre compte la copie
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then : 404, comme pour une recette inconnue (au message près de l'identifiant), et rien n'est écrit
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame($unknown[0], $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->copier->getId()));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient WHERE creator = ?', $this->copier->getId()));

        // When : l'auteur copie sa propre secrète
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->author);

        // Then : la copie est secrète et utilise la même pâte, secrète elle aussi
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame([$this->author->getId(), $this->tart->getId(), true], $this->recipeRow($copyId));
        $this->request('GET', "/api/v1/recipes/$copyId", $this->author);
        $this->assertSame([$this->dough->getId()], array_column($this->jsonBody()['components'], 'recipeId'));
    }

    public function testAPartOfAnIngredientUsedByTheRecipeAndByItsUsedRecipeIsLinkedOnceForTheCopier(): void
    {
        // Given : l'auteur a un jaune d'œuf, partie de son œuf, dans sa pâte ; sa tarte utilise l'œuf et la pâte
        $yolk = new IngredientBuilder()->withName('Jaune d\'œuf')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->author, true)->withParent($this->egg->getSlug(), 1.0)->build();
        $this->persister->saveIngredient($yolk);
        $yolkDough = $this->savedRecipe('Pâte au jaune', 4, [[$yolk, 3]]);
        $tart = $this->savedRecipe('Tarte au jaune', 4, [[$this->egg, 2], [$yolk, 1]], [[$yolkDough, 1.0]]);

        // When
        $this->request('POST', '/api/v1/recipes/' . $tart->getId() . '/copy', $this->copier);

        // Then : le jaune du copieur est une partie de SON œuf (jamais celui de l'auteur), liée une fois, au même rapport
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $mine = $this->ingredientSlugsOf($this->copier);
        $this->assertEqualsCanonicalizing(['Jaune d\'œuf', 'Œuf'], array_keys($mine));
        $this->assertSame([$mine['Œuf'], 1.0], $this->partOf($mine['Jaune d\'œuf']));
        $this->assertSame([null, null], $this->partOf($mine['Œuf']));
    }

    public function testTheLimitOfTheAccountCountsTheUsedRecipesToCreate(): void
    {
        // Given : le copieur a 1 999 recettes ; la tarte et sa pâte feraient 2 001
        $this->fillRecipesOf($this->copier, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 1);

        // When
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then : 422, rien n'est écrit (ni recette, ni ingrédient, ni arête)
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(RecipeException::LIMIT_REACHED, $this->jsonBody()['error']['message']);
        $this->assertSame(Recipe::MAX_PER_AUTHOR_WITH_COPIES - 1, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->copier->getId()));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient WHERE creator = ?', $this->copier->getId()));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM recipe_component rc JOIN recipe r ON r.id = rc.recipe WHERE r.author = ?', $this->copier->getId()));

        // When : une recette de moins en base, la même copie tient juste dans le plafond
        $this->entityManager->getConnection()->executeStatement("DELETE FROM recipe WHERE id = (SELECT id FROM recipe WHERE author = ? LIMIT 1)", [$this->copier->getId()]);
        $this->request('POST', '/api/v1/recipes/' . $this->tart->getId() . '/copy', $this->copier);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(Recipe::MAX_PER_AUTHOR_WITH_COPIES, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->copier->getId()));
    }

    // --- Aides ---

    private function savedIngredient(string $name, DepartmentBuilder $department, UnitBuilder $cooking, UnitBuilder $purchase): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit($cooking)->withDefaultPurchaseUnit($purchase)->withCreator($this->author, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @param list<array{Ingredient, int}> $rows la quantité est comptée à l'unité de cuisine de l'ingrédient (le gramme, ou l'unité de l'œuf)
     * @param list<array{Recipe, float}> $uses
     */
    private function savedRecipe(string $name, int $serving, array $rows, array $uses = []): Recipe
    {
        return $this->savedRecipeFor($this->author, $name, $serving, $rows, $uses);
    }

    /**
     * @param list<array{Ingredient, int}> $rows
     * @param list<array{Recipe, float}> $uses
     */
    private function savedRecipeFor(User $author, string $name, int $serving, array $rows, array $uses = []): Recipe
    {
        $builder = new RecipeBuilder()->withName($name)->withServing($serving)->withAuthor($author)->withRecipeType(new RecipeTypeBuilder()->isDessert());
        foreach ($rows as [$ingredient, $quantity]) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($ingredient->getDefaultCookingUnit()->getSlug() === 'unite' ? new UnitBuilder()->isUnite() : new UnitBuilder()->isGramme())->withQuantity($quantity));
        }
        foreach ($uses as [$used, $multiplier]) {
            $builder->withComponent($used, $multiplier);
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /** Un ingrédient du compte, par son nom (il l'a reçu par une copie). */
    private function ingredientOf(User $user, string $name): Ingredient
    {
        $slug = $this->ingredientSlugsOf($user)[$name];

        return static::getContainer()->get(IngredientRepository::class)->findOneBySlug($slug);
    }

    /**
     * @return array<string, string> le slug des ingrédients du compte, par nom
     */
    private function ingredientSlugsOf(User $user): array
    {
        return array_flip($this->entityManager->getConnection()->fetchAllKeyValue('SELECT slug, name FROM ingredient WHERE creator = ?', [$user->getId()]));
    }

    /**
     * @return array{0: string|null, 1: float|null} le parent d'un ingrédient et le rapport
     */
    private function partOf(string $slug): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$slug]);

        return [$row['parent_slug'], $row['parent_ratio'] === null ? null : (float) $row['parent_ratio']];
    }

    /**
     * @return array{0: string, 1: string|null, 2: bool} l'auteur d'une recette, son original et si elle est secrète
     */
    private function recipeRow(string $recipeId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT author, orignal, secret FROM recipe WHERE id = ?', [$recipeId]);

        return [$row['author'], $row['orignal'], (bool) $row['secret']];
    }

    private function fillRecipesOf(User $user, int $count): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, version) SELECT gen_random_uuid()::text, 'Remplissage ' || n, 'remplissage-' || n, 4, ?, 'dessert', 1 FROM generate_series(1, ?) AS n",
            [$user->getId(), $count],
        );
    }

    private function createdList(User $user): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $user);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return basename($this->client->getResponse()->headers->get('Location'));
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

    /**
     * @return list<string>
     */
    private function mealRecipes(string $listId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT recipe FROM meal WHERE shopping_list = ? ORDER BY recipe', [$listId]);
    }

    private function scalar(string $sql, mixed ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
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

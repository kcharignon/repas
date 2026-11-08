<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Découper un ingrédient en parties (ADR 0038, SUB-001) de bout en bout, avec les vrais bus, la vraie base et la vraie sécurité : `PUT` et
 * `DELETE /api/v1/ingredients/{slug}/parent`, le lien lu des deux côtés, les cinq erreurs nommées, et l'absence de passe-droit administrateur.
 */
class IngredientPartApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $other;
    private User $admin;
    private Ingredient $egg;
    private Ingredient $yolk;
    private Ingredient $white;
    private Ingredient $vanilla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->other = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
        $this->egg = $this->savedIngredient('Œuf', $this->owner);
        $this->yolk = $this->savedIngredient("Jaune d'œuf", $this->owner);
        $this->white = $this->savedIngredient("Blanc d'œuf", $this->owner);
        $this->vanilla = $this->savedIngredient('Vanille', $this->owner);
    }

    public function testCuttingAnIngredientIsTold204AndTheLinkIsReadFromBothSides(): void
    {
        // When
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $put = $this->client->getResponse();
        $this->put($this->white, $this->egg, 0.5, $this->owner);

        // Then : 204 sans corps
        $this->assertSame([204, ''], [$put->getStatusCode(), $put->getContent()]);
        // le parent dit ses parties, triées par nom, avec leur ratio et l'unité de chacune
        $this->request('GET', '/api/v1/ingredients/' . $this->egg->getSlug(), $this->owner);
        $parent = $this->jsonBody();
        $this->assertSame(
            [
                ['slug' => $this->white->getSlug(), 'name' => "Blanc d'œuf", 'ratio' => 0.5, 'unitName' => 'unité'],
                ['slug' => $this->yolk->getSlug(), 'name' => "Jaune d'œuf", 'ratio' => 1, 'unitName' => 'unité'],
            ],
            $parent['parts'],
        );
        $this->assertNull($parent['partOf']);
        // la partie dit son parent
        $this->request('GET', '/api/v1/ingredients/' . $this->yolk->getSlug(), $this->owner);
        $part = $this->jsonBody();
        $this->assertSame(['slug' => $this->egg->getSlug(), 'name' => 'Œuf', 'ratio' => 1], $part['partOf']);
        $this->assertSame([], $part['parts']);
        // un ingrédient ordinaire a les deux clés vides
        $this->request('GET', '/api/v1/ingredients/' . $this->vanilla->getSlug(), $this->owner);
        $this->assertSame([[], null], [$this->jsonBody()['parts'], $this->jsonBody()['partOf']]);
    }

    public function testTheChoicesAndTheSearchAndTheRecipeSayWhichIngredientIsAPart(): void
    {
        // Given : la recette est enregistrée AVANT le découpage (l'enregistrer relit les modèles construits ici, qui ne connaissent pas le lien)
        $recipe = new RecipeBuilder()->withAuthor($this->owner)->withName('Mousse')->withRecipeType(new RecipeTypeBuilder()->isDessert())
            ->addRow(new RecipeRowBuilder()->withIngredient($this->yolk)->withUnit(new UnitBuilder()->isUnite())->withQuantity(3))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->vanilla)->withUnit(new UnitBuilder()->isUnite())->withQuantity(1))
            ->build();
        $this->persister->saveRecipe($recipe);
        $this->put($this->yolk, $this->egg, 1, $this->owner);

        // When / Then : la liste des ingrédients
        $this->request('GET', '/api/v1/ingredients', $this->owner);
        $byName = array_column($this->jsonBody()['member'], null, 'name');
        $this->assertSame([$this->egg->getSlug(), 'Œuf'], [$byName["Jaune d'œuf"]['partOfSlug'], $byName["Jaune d'œuf"]['partOfName']]);
        $this->assertSame([null, null], [$byName['Œuf']['partOfSlug'], $byName['Œuf']['partOfName']]);
        // la recherche
        $this->request('GET', '/api/v1/ingredients/search?q=jaune', $this->owner);
        $this->assertSame([$this->yolk->getSlug(), $this->egg->getSlug(), 'Œuf'], [$this->jsonBody()['member'][0]['slug'], $this->jsonBody()['member'][0]['partOfSlug'], $this->jsonBody()['member'][0]['partOfName']]);
        // une ligne de recette
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->owner);
        $this->assertSame(['Œuf', null], array_column($this->jsonBody()['rows'], 'partOfName'));
    }

    public function testTheSameCallTwiceIsIdempotentAndTheRatioCanBeChanged(): void
    {
        // When
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $twice = $this->client->getResponse()->getStatusCode();
        $this->put($this->yolk, $this->egg, 2, $this->owner);
        $changed = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([204, 204], [$twice, $changed]);
        $this->assertSame([$this->egg->getSlug(), 2.0], $this->storedParent($this->yolk));
    }

    public function testAPartChangesOfParent(): void
    {
        // Given
        $this->put($this->yolk, $this->egg, 1, $this->owner);

        // When
        $this->put($this->yolk, $this->vanilla, 3, $this->owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$this->vanilla->getSlug(), 3.0], $this->storedParent($this->yolk));
    }

    public function testGluingBackUndoesTheLinkOnBothSidesAndIsIdempotent(): void
    {
        // Given
        $this->put($this->yolk, $this->egg, 1, $this->owner);

        // When
        $this->request('DELETE', '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', $this->owner);
        $deleted = $this->client->getResponse();
        $this->request('DELETE', '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', $this->owner);
        $again = $this->client->getResponse()->getStatusCode();
        $this->request('DELETE', '/api/v1/ingredients/' . $this->vanilla->getSlug() . '/parent', $this->owner);
        $ordinary = $this->client->getResponse()->getStatusCode();

        // Then : 204 sans corps, rejoué ou sur un ingrédient ordinaire aussi
        $this->assertSame([204, ''], [$deleted->getStatusCode(), $deleted->getContent()]);
        $this->assertSame([204, 204], [$again, $ordinary]);
        $this->assertSame([null, null], $this->storedParent($this->yolk));
        $this->request('GET', '/api/v1/ingredients/' . $this->egg->getSlug(), $this->owner);
        $this->assertSame([], $this->jsonBody()['parts']);
        $this->request('GET', '/api/v1/ingredients/' . $this->yolk->getSlug(), $this->owner);
        $this->assertNull($this->jsonBody()['partOf']);
    }

    public function testAnAdministratorCutsHisOwnIngredientsLikeAnyone(): void
    {
        // Given
        $adminEgg = $this->savedIngredient('Œuf', $this->admin);
        $adminYolk = $this->savedIngredient("Jaune d'œuf", $this->admin);

        // When
        $this->put($adminYolk, $adminEgg, 1, $this->admin);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$adminEgg->getSlug(), 1.0], $this->storedParent($adminYolk));
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        foreach ([['PUT', '{"parent":"x","ratio":1}'], ['DELETE', null]] as [$method, $body]) {
            // When
            $this->client->request($method, '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', content: $body, server: ['CONTENT_TYPE' => 'application/json']);

            // Then
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), $method);
        }
        $this->assertSame([null, null], $this->storedParent($this->yolk));
    }

    public function testTheIngredientOfAnotherAccountIsForbiddenAdministratorIncluded(): void
    {
        // Given : le jaune du propriétaire est déjà une partie ; l'autre compte a son propre œuf
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $theirEgg = $this->savedIngredient('Œuf', $this->other);

        foreach ([$this->other, $this->admin] as $caller) {
            // When : couper, changer ou recoller l'ingrédient d'un autre
            $this->put($this->white, $this->egg, 1, $caller);
            $cut = $this->client->getResponse()->getStatusCode();
            $this->put($this->yolk, $theirEgg, 5, $caller);
            $changed = $this->client->getResponse()->getStatusCode();
            $this->request('DELETE', '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', $caller);
            $deleted = $this->client->getResponse()->getStatusCode();

            // Then : pas de passe-droit, et rien n'a bougé
            $this->assertSame([403, 403, 403], [$cut, $changed, $deleted], (string) $caller->getId());
            $this->assertSame([$this->egg->getSlug(), 1.0], $this->storedParent($this->yolk));
            $this->assertSame([null, null], $this->storedParent($this->white));
        }
    }

    public function testTheParentOfAnotherAccountIsNotFoundAdministratorIncludedAndNothingIsWritten(): void
    {
        // Given
        $theirEgg = $this->savedIngredient('Œuf', $this->other);
        $adminsYolk = $this->savedIngredient("Jaune d'œuf", $this->admin);

        // When / Then : le même refus pour le parent d'un autre, du propriétaire comme de l'administrateur, et pour un parent inconnu
        $this->put($this->yolk, $theirEgg, 1, $this->owner);
        $this->assertSame([404, ['error' => ['message' => "Ingredient '" . $theirEgg->getSlug() . "' not found"]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->put($adminsYolk, $theirEgg, 1, $this->admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->put($this->yolk, 'inconnu', 1, $this->owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([null, null], $this->storedParent($this->yolk));
        $this->assertSame([null, null], $this->storedParent($adminsYolk));
    }

    public function testAnUnknownIngredientIsNotFound(): void
    {
        // When
        $this->request('PUT', '/api/v1/ingredients/inconnu/parent', $this->owner, ['parent' => $this->egg->getSlug(), 'ratio' => 1]);
        $put = $this->client->getResponse()->getStatusCode();
        $this->request('DELETE', '/api/v1/ingredients/inconnu/parent', $this->owner);

        // Then
        $this->assertSame([404, 404], [$put, $this->client->getResponse()->getStatusCode()]);
    }

    public function testTheFiveCasesAreToldByTheirOwnMessage(): void
    {
        // 1. un ingrédient n'est pas sa propre partie
        $this->put($this->yolk, $this->yolk, 1, $this->owner);
        $this->assertSame([422, ['error' => ['message' => 'INGREDIENT_PART_OF_ITSELF']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // 2. une chaîne, dans les deux sens : le parent est une partie, ou l'ingrédient a des parties
        $this->put($this->white, $this->egg, 1, $this->owner);
        $this->put($this->yolk, $this->white, 1, $this->owner);
        $this->assertSame([422, ['error' => ['message' => 'INGREDIENT_PART_CHAIN']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->put($this->egg, $this->vanilla, 1, $this->owner);
        $this->assertSame([422, ['error' => ['message' => 'INGREDIENT_PART_CHAIN']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->put($this->egg, $this->white, 1, $this->owner);
        $this->assertSame([422, ['error' => ['message' => 'INGREDIENT_PART_CHAIN']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()], 'le cycle');

        // 3. la onzième partie
        foreach (range(1, Ingredient::MAX_PARTS - 1) as $i) {
            $this->put($this->savedIngredient("Partie $i", $this->owner), $this->egg, 1, $this->owner);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "partie $i");
        }
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $this->assertSame([422, ['error' => ['message' => 'INGREDIENT_PART_LIMIT_REACHED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        // une partie déjà reliée change toujours de ratio, même quand le parent est plein
        $this->put($this->white, $this->egg, 7, $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // 4. le ratio (jugé par le corps de la requête, qui répète le message de l'erreur métier)
        foreach ([0, -1, 1_000_001, 0.0000001, 'deux', '2', null, [1], true] as $ratio) {
            $this->put($this->vanilla, $this->egg, $ratio, $this->owner);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($ratio));
            $this->assertSame('ratio: INGREDIENT_PART_RATIO_INVALID', $this->jsonBody()['error']['message'], json_encode($ratio));
        }
        $this->assertSame([null, null], $this->storedParent($this->vanilla));

        // 5. une ligne dans une liste non terminée
        $list = new ShoppingListBuilder()->withOwner($this->owner)->addIngredient($this->vanilla)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);
        $this->put($this->vanilla, $this->savedIngredient('Gousse', $this->owner), 2, $this->owner);
        $this->assertSame([409, ['error' => ['message' => 'INGREDIENT_PART_IN_LIST']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame([null, null], $this->storedParent($this->vanilla));
    }

    public function testAListThatIsCompletedOrWithoutTheRowDoesNotStopTheCut(): void
    {
        // Given : la liste terminée garde sa ligne de vanille ; une autre liste en cours ne porte que l'œuf
        $lists = static::getContainer()->get(ShoppingListRepository::class);
        $lists->save(new ShoppingListBuilder()->withOwner($this->owner)->addIngredient($this->vanilla)->withStatus(ShoppingListStatus::COMPLETED)->build());
        $lists->save(new ShoppingListBuilder()->withOwner($this->owner)->addIngredient($this->egg)->build());

        // When
        $this->put($this->vanilla, $this->egg, 1, $this->owner);
        $vanilla = $this->client->getResponse()->getStatusCode();
        $this->put($this->yolk, $this->egg, 1, $this->owner);
        $yolk = $this->client->getResponse()->getStatusCode();

        // Then : une liste terminée ne compte plus ; la ligne du parent ne gêne pas le découpage de la partie
        $this->assertSame([204, 204], [$vanilla, $yolk]);
    }

    public function testTheInvalidBodiesAreRefused(): void
    {
        foreach ([[], ['ratio' => 1], ['parent' => $this->egg->getSlug()], ['parent' => ''], ['parent' => null, 'ratio' => 1], ['parent' => 3, 'ratio' => 1], ['parent' => str_repeat('a', 256), 'ratio' => 1]] as $body) {
            // When
            $this->request('PUT', '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', $this->owner, $body);

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($body));
        }
        $this->assertSame([null, null], $this->storedParent($this->yolk));
    }

    public function testTheOldSlugOfTheParentInTheBodyIsTranslatedByTheAliases(): void
    {
        // Given : l'ancien slug de l'œuf (un commun dont le compte a reçu une copie, ou un ingrédient fusionné)
        $this->entityManager->getConnection()->insert('ingredient_alias', [
            'user_id' => $this->owner->getId(), 'alias_slug' => 'oeuf-commun', 'ingredient_slug' => $this->egg->getSlug(),
            'kind' => 'adoption', 'source' => 'activation', 'created_at' => '2026-11-08 10:00:00',
        ]);

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $this->yolk->getSlug() . '/parent', $this->owner, ['parent' => 'oeuf-commun', 'ratio' => 1]);

        // Then : le lien est posé sur l'ingrédient actuel, jamais sur l'ancien slug
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$this->egg->getSlug(), 1.0], $this->storedParent($this->yolk));
    }

    /**
     * @param mixed $ratio
     */
    private function put(Ingredient $ingredient, Ingredient|string $parent, mixed $ratio, User $user): void
    {
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug() . '/parent', $user, ['parent' => $parent instanceof Ingredient ? $parent->getSlug() : $parent, 'ratio' => $ratio]);
    }

    /**
     * @return array{?string, ?float}
     */
    private function storedParent(Ingredient $ingredient): array
    {
        $this->entityManager->clear();
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$ingredient->getSlug()]);

        return [$row['parent_slug'], $row['parent_ratio'] === null ? null : (float) $row['parent_ratio']];
    }

    private function savedIngredient(string $name, User $creator): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withCreator($creator, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
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

<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Les doublons possibles et « Pas le même » de bout en bout (ADR 0028 décision h, ING-006), avec les vrais bus, la vraie base, les
 * listeners et le JWT : un utilisateur crée « Tomate », « Tomates », « Concombre », « Concombrre », « Poivre » et « Poivre noir », les
 * utilise dans des recettes, lit ses doublons, en écarte un, en fusionne un autre, supprime un ingrédient, et vérifie qu'un autre
 * utilisateur, ou l'administrateur, ne voit ni n'écarte rien de lui.
 */
class DuplicateIngredientsApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $persister = new DataPersister(static::getContainer());
        $persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
    }

    public function testTheUserReadsHisDuplicatesDismissesOneMergesAnotherAndNothingLeaksToAnyoneElse(): void
    {
        // Given : six ingrédients (deux paires de noms proches, deux noms dont l'un précise l'autre) ; « Tomates » est dans deux recettes
        $user = $this->savedUser();
        $tomato = $this->createIngredient($user, 'Tomate');
        $tomatoes = $this->createIngredient($user, 'Tomates');
        $cucumber = $this->createIngredient($user, 'Concombre');
        $cucumberTypo = $this->createIngredient($user, 'Concombrre');
        $pepper = $this->createIngredient($user, 'Poivre');
        $blackPepper = $this->createIngredient($user, 'Poivre noir');
        $this->createRecipe($user, 'Salade', [[$tomatoes, 300], [$cucumber, 100]]);
        $this->createRecipe($user, 'Sauce', [[$tomatoes, 500], [$tomato, 100]]);

        // When
        $this->request('GET', '/api/v1/ingredients/duplicates', $user);

        // Then : deux propositions, la plus probable d'abord ; le nom précisé n'est pas proposé
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = $this->jsonBody();
        $this->assertSame(2, $body['totalItems']);
        [$exact, $typo] = $body['member'];
        $this->assertSame(['EXACT', 1], [$exact['level'], $exact['score']]);
        $this->assertSame('TYPO', $typo['level']);
        $this->assertGreaterThanOrEqual(0.97, $typo['score']);
        $this->assertSame([$tomato, $tomatoes], [$exact['first']['slug'], $exact['second']['slug']]);
        $this->assertSame(['slug', 'name', 'departmentSlug', 'departmentName', 'purchaseUnitName', 'recipeCount', 'listCount'], array_keys($exact['first']));
        $this->assertSame(['first', 'second', 'score', 'level', 'suggestedKeep'], array_keys($exact));
        // la carte se dessine sans second appel : noms, rayon, unité d'achat, nombres de recettes ; « Tomates » est la plus utilisée
        $this->assertSame(['Tomate', 'boulangerie', 'kilo', 1, 0], [$exact['first']['name'], $exact['first']['departmentSlug'], $exact['first']['purchaseUnitName'], $exact['first']['recipeCount'], $exact['first']['listCount']]);
        $this->assertSame(['Tomates', 2], [$exact['second']['name'], $exact['second']['recipeCount']]);
        $this->assertSame($tomatoes, $exact['suggestedKeep']);
        $this->assertSame([$cucumber, $cucumberTypo], [$typo['first']['slug'], $typo['second']['slug']]);
        $this->assertSame($cucumber, $typo['suggestedKeep'], 'Le plus utilisé en recettes (1 contre 0).');
        $this->assertNotContains($pepper, array_merge(array_column(array_column($body['member'], 'first'), 'slug'), array_column(array_column($body['member'], 'second'), 'slug')));
        $this->assertNotContains($blackPepper, array_column(array_column($body['member'], 'second'), 'slug'));

        // Given : un autre utilisateur, qui a son « Tomate » et son « Tomates », et l'administrateur, qui n'a aucun ingrédient personnel
        $other = $this->savedUser();
        $otherTomato = $this->createIngredient($other, 'Tomate');
        $otherTomatoes = $this->createIngredient($other, 'Tomates');
        $admin = $this->savedUser(true);

        // Then : chacun ne voit que les siens ; l'administrateur a une liste vide, pas une erreur
        $this->request('GET', '/api/v1/ingredients/duplicates', $other);
        $this->assertSame([[$otherTomato, $otherTomatoes]], array_map(static fn(array $d): array => [$d['first']['slug'], $d['second']['slug']], $this->jsonBody()['member']));
        $this->request('GET', '/api/v1/ingredients/duplicates', $admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());

        // Then : ni l'autre utilisateur ni l'administrateur ne peut écarter une paire de l'utilisateur (404), ni mêler un ingrédient d'un autre à la sienne
        foreach ([$other, $admin] as $intruder) {
            $this->request('POST', "/api/v1/ingredients/$tomato/distinct-from", $intruder, ['other' => $tomatoes]);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        }
        $this->request('POST', "/api/v1/ingredients/$otherTomato/distinct-from", $other, ['other' => $tomato]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM ingredient_distinct'));

        // Then : un ingrédient n'est pas « pas le même » que lui-même ; un corps invalide est refusé ; sans jeton, 401
        $this->request('POST', "/api/v1/ingredients/$tomato/distinct-from", $user, ['other' => $tomato]);
        $this->assertSame([422, 'CANNOT_BE_DISTINCT_FROM_ITSELF'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
        $this->request('POST', "/api/v1/ingredients/$tomato/distinct-from", $user, []);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', "/api/v1/ingredients/$tomato/distinct-from", content: json_encode(['other' => $tomatoes]), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());

        // When : « Pas le même » pour Tomate / Tomates (dans l'ordre inverse de celui de la table), rejoué dans les deux ordres
        $before = $this->ingredientsHash();
        $this->request('POST', "/api/v1/ingredients/$tomatoes/distinct-from", $user, ['other' => $tomato]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/ingredients/$tomatoes/distinct-from", $user, ['other' => $tomato]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/ingredients/$tomato/distinct-from", $user, ['other' => $tomatoes]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : une seule ligne, rien d'autre n'a changé, la paire ne revient pas, l'autre paire reste ; la paire de l'autre utilisateur non plus
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM ingredient_distinct'));
        $this->assertSame($before, $this->ingredientsHash());
        $this->request('GET', '/api/v1/ingredients/duplicates', $user);
        $this->assertSame([[$cucumber, $cucumberTypo]], array_map(static fn(array $d): array => [$d['first']['slug'], $d['second']['slug']], $this->jsonBody()['member']));
        $this->request('GET', '/api/v1/ingredients/duplicates', $other);
        $this->assertCount(1, $this->jsonBody()['member']);

        // When : l'utilisateur renomme « Tomates » en « Tomate » (même nom exactement, même rayon) : refusé, un rayon n'a pas deux ingrédients
        // du même nom (ING-016) ; la paire écartée se fusionne, elle ne se renomme pas en doublon exact
        $this->request('PUT', "/api/v1/ingredients/$tomatoes", $user, ['name' => 'Tomate', 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000]);
        $this->assertSame([409, 'INGREDIENT_NAME_ALREADY_USED'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);

        // When : il le renomme en « Tomatte » (à une faute près)
        $this->request('PUT', "/api/v1/ingredients/$tomatoes", $user, ['name' => 'Tomatte', 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        // Then : jamais reproposée
        $this->request('GET', '/api/v1/ingredients/duplicates', $user);
        $this->assertSame(1, $this->jsonBody()['totalItems']);

        // When : il fusionne « Concombrre » dans « Concombre » (la paire proposée)
        $this->request('POST', "/api/v1/ingredients/$cucumberTypo/distinct-from", $user, ['other' => $pepper]);
        $this->request('POST', "/api/v1/ingredients/$pepper/distinct-from", $user, ['other' => $blackPepper]);
        $this->assertSame(3, $this->rows('SELECT COUNT(*) FROM ingredient_distinct'));
        $this->request('POST', "/api/v1/ingredients/$cucumberTypo/merge", $user, ['into' => $cucumber]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : la paire fusionnée disparaît de la liste, et les « Pas le même » qui nommaient l'absorbé disparaissent de la mémoire
        $this->request('GET', '/api/v1/ingredients/duplicates', $user);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
        $this->assertSame(2, $this->rows('SELECT COUNT(*) FROM ingredient_distinct'));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM ingredient_distinct WHERE first_slug = ? OR second_slug = ?', $cucumberTypo, $cucumberTypo));

        // When : il supprime « Poivre noir » (inutilisé)
        $this->request('DELETE', "/api/v1/ingredients/$blackPepper", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        // Then : la paire « Poivre / Poivre noir » est oubliée avec lui, celle de Tomate reste (et celle de l'autre utilisateur, intacte)
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM ingredient_distinct WHERE user_id = ?', $user->getId()));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM ingredient_distinct WHERE first_slug = ? OR second_slug = ?', $blackPepper, $blackPepper));
    }

    public function testAMergedSlugThatComesBackIsNotHiddenByTheOldDismissal(): void
    {
        // Given : « Tomate » et « Tomates » écartées, puis « Tomates » fusionnée dans « Tomate » et recréée
        $user = $this->savedUser();
        $tomato = $this->createIngredient($user, 'Tomate');
        $tomatoes = $this->createIngredient($user, 'Tomates');
        $this->request('POST', "/api/v1/ingredients/$tomato/distinct-from", $user, ['other' => $tomatoes]);
        $this->request('POST', "/api/v1/ingredients/$tomatoes/merge", $user, ['into' => $tomato]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When
        $again = $this->createIngredient($user, 'Tomates');
        $this->request('GET', '/api/v1/ingredients/duplicates', $user);

        // Then : le même slug revient (le nom et le créateur le donnent), la mémoire de l'ancien ingrédient n'a pas survécu
        $this->assertSame($tomatoes, $again);
        $this->assertSame([[$tomato, $tomatoes]], array_map(static fn(array $d): array => [$d['first']['slug'], $d['second']['slug']], $this->jsonBody()['member']));
    }

    // --- Outils ---------------------------------------------------------------------------------------------------------------------

    private function createIngredient(User $user, string $name): string
    {
        $this->request('POST', '/api/v1/ingredients', $user, ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @param list<array{string, int}> $rows `[slug, quantité en gramme]`
     */
    private function createRecipe(User $user, string $name, array $rows): void
    {
        $this->request('POST', '/api/v1/recipes', $user, [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => array_map(static fn(array $row): array => ['ingredientSlug' => $row[0], 'unitSlug' => 'gramme', 'quantity' => $row[1]], $rows),
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
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

    private function rows(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }

    private function ingredientsHash(): string
    {
        $this->entityManager->clear();

        return (string) $this->entityManager->getConnection()->fetchOne("SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t::text COLLATE \"C\"), '')) FROM ingredient t");
    }
}

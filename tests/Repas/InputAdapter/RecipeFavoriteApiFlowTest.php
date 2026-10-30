<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
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
 * Les recettes favorites (ADR 0034) de bout en bout, avec les vrais bus, la vraie base et la vraie sécurité : l'auteur met une recette en
 * favoris, elle est dans sa liste « Favoris » ET dans son type ; seul l'auteur change le choix ; la copie n'est jamais favorite.
 */
class RecipeFavoriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $author;
    private User $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->author = $this->savedUser();
        $this->other = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
    }

    public function testWithoutFavoriteTheListIsEmpty(): void
    {
        // Given
        $this->savedRecipe($this->author, 'Tarte');

        // When
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testAFavoriteRecipeIsInTheFavoritesAndStillInItsType(): void
    {
        // Given
        $favorite = $this->savedRecipe($this->author, 'Tarte aux pommes');
        $ordinary = $this->savedRecipe($this->author, 'Quiche');

        // When
        $this->request('PUT', '/api/v1/recipes/' . $favorite->getId() . '/favorite', $this->author, ['favorite' => true]);
        $put = $this->client->getResponse()->getStatusCode();
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $favorites = $this->jsonBody();
        $this->request('GET', '/api/v1/recipe-types/plat/recipes', $this->author);
        $inType = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/' . $favorite->getId(), $this->author);
        $detail = $this->jsonBody();

        // Then : dans les favoris et dans son type, pas l'autre ; la fiche le dit
        $this->assertSame(204, $put);
        $this->assertSame([[$favorite->getId()], 1], [array_column($favorites['member'], 'id'), $favorites['totalItems']]);
        $this->assertSame(['id' => $favorite->getId(), 'name' => 'Tarte aux pommes', 'secret' => false], $favorites['member'][0]);
        $this->assertEqualsCanonicalizing([$favorite->getId(), $ordinary->getId()], array_column($inType['member'], 'id'));
        $this->assertTrue($detail['favorite']);
        $this->request('GET', '/api/v1/recipes/' . $ordinary->getId(), $this->author);
        $this->assertFalse($this->jsonBody()['favorite']);
    }

    public function testTakingARecipeOutOfFavoritesEmptiesTheListAgain(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte', favorite: true);

        // When
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId() . '/favorite', $this->author, ['favorite' => false]);
        $put = $this->client->getResponse()->getStatusCode();
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);

        // Then
        $this->assertSame(204, $put);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testTheFavoritesAreSortedByNameAndBelongToTheirAuthorOnly(): void
    {
        // Given
        $this->savedRecipe($this->author, 'Tarte', favorite: true);
        $this->savedRecipe($this->author, 'Blanquette', favorite: true);
        $this->savedRecipe($this->other, 'Aligot', favorite: true);

        // When
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $mine = array_column($this->jsonBody()['member'], 'name');
        $this->request('GET', '/api/v1/recipes/favorites', $this->other);
        $theirs = array_column($this->jsonBody()['member'], 'name');
        $this->request('GET', '/api/v1/recipes/favorites', $this->admin);
        $admins = $this->jsonBody()['member'];

        // Then
        $this->assertSame(['Blanquette', 'Tarte'], $mine);
        $this->assertSame(['Aligot'], $theirs);
        $this->assertSame([], $admins);
    }

    public function testASecretRecipeCanBeAFavoriteOfItsAuthorAndKeepsBeingSecret(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);

        // When
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId() . '/favorite', $this->author, ['favorite' => true]);
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $favorites = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people', $this->other);

        // Then : dans les favoris de l'auteur, avec sa pastille, et toujours absente des recettes des autres
        $this->assertSame([[$secret->getId(), true]], array_map(static fn(array $recipe): array => [$recipe['id'], $recipe['secret']], $favorites['member']));
        $this->assertSame([], $this->jsonBody()['member']);
    }

    public function testAnotherUserNeitherChangesNorSeesTheFavoriteOfTheAuthor(): void
    {
        // Given
        $favorite = $this->savedRecipe($this->author, 'Tarte', favorite: true);
        $ordinary = $this->savedRecipe($this->author, 'Quiche');

        // When : un utilisateur, puis un administrateur, essaient de changer le choix de l'auteur
        $this->request('PUT', '/api/v1/recipes/' . $ordinary->getId() . '/favorite', $this->other, ['favorite' => true]);
        $user = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $ordinary->getId() . '/favorite', $this->admin, ['favorite' => true]);
        $admin = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $this->request('PUT', '/api/v1/recipes/' . $favorite->getId() . '/favorite', $this->admin, ['favorite' => false]);
        $adminUnfavors = $this->client->getResponse()->getStatusCode();
        // et la fiche qu'un autre lit ne dit pas que l'auteur l'a en favoris
        $this->request('GET', '/api/v1/recipes/' . $favorite->getId(), $this->other);
        $read = $this->jsonBody();

        // Then
        $this->assertSame(403, $user);
        $this->assertSame([403, ['error' => ['message' => RecipeException::FAVORITE_FORBIDDEN]]], $admin);
        $this->assertSame(403, $adminUnfavors);
        $this->assertFalse($read['favorite']);
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $this->assertSame([$favorite->getId()], array_column($this->jsonBody()['member'], 'id'));
    }

    public function testTheSecretRecipeOfAnotherIsUnknownAndAnUnknownRecipeIsNotFound(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $unknown = '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34';

        // When
        $this->request('PUT', '/api/v1/recipes/' . $unknown . '/favorite', $this->admin, ['favorite' => true]);
        $expected = [$this->client->getResponse()->getStatusCode(), str_replace($unknown, '{id}', (string) $this->client->getResponse()->getContent())];
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId() . '/favorite', $this->admin, ['favorite' => true]);
        $adminAnswer = [$this->client->getResponse()->getStatusCode(), str_replace($secret->getId(), '{id}', (string) $this->client->getResponse()->getContent())];
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId() . '/favorite', $this->other, ['favorite' => true]);
        $otherAnswer = [$this->client->getResponse()->getStatusCode(), str_replace($secret->getId(), '{id}', (string) $this->client->getResponse()->getContent())];

        // Then : la même réponse qu'un identifiant inconnu, jamais un 403
        $this->assertSame(404, $expected[0]);
        $this->assertSame($expected, $adminAnswer);
        $this->assertSame($expected, $otherAnswer);
    }

    public function testAnOutdatedVersionIsRefusedAndTheFavoriteIsKept(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte');
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->author);
        $etag = $this->client->getResponse()->headers->get('ETag');
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId() . '/favorite', $this->author, ['favorite' => true]);

        // When : l'étiquette lue avant la mise en favoris est périmée
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId() . '/favorite', $this->author, ['favorite' => false], ['HTTP_IF_MATCH' => $etag]);

        // Then
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $this->assertSame([$recipe->getId()], array_column($this->jsonBody()['member'], 'id'));
    }

    public function testRefusesAnythingButABoolean(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte');

        // When & Then
        foreach ([[], ['favorite' => null], ['favorite' => 'true'], ['favorite' => 1]] as $body) {
            $this->request('PUT', '/api/v1/recipes/' . $recipe->getId() . '/favorite', $this->author, $body);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($body));
        }
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $this->assertSame([], $this->jsonBody()['member']);
    }

    public function testEditingAFavoriteRecipeKeepsItFavorite(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte', favorite: true);
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->author);
        $rows = array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], $this->jsonBody()['rows']);

        // When : la recette est remplacée par un corps qui ne parle pas des favoris
        $this->request('PUT', '/api/v1/recipes/' . $recipe->getId(), $this->author, ['name' => 'Tarte revue', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => $rows]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/recipes/favorites', $this->author);
        $this->assertSame(['Tarte revue'], array_column($this->jsonBody()['member'], 'name'));
    }

    public function testTheCopyOfAFavoriteRecipeIsNotFavorite(): void
    {
        // Given : une recette favorite de son auteur, copiée par un autre
        $favorite = $this->savedRecipe($this->author, 'Tarte', favorite: true);

        // When
        $this->request('POST', '/api/v1/recipes/' . $favorite->getId() . '/copy', $this->other);
        $copy = $this->client->getResponse();
        $this->request('GET', '/api/v1/recipes/favorites', $this->other);

        // Then
        $this->assertSame(201, $copy->getStatusCode());
        $this->assertSame([], $this->jsonBody()['member']);
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When & Then
        $this->client->request('GET', '/api/v1/recipes/favorites');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('PUT', '/api/v1/recipes/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34/favorite', content: '{"favorite":true}', server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function savedRecipe(User $author, string $name, bool $secret = false, bool $favorite = false): Recipe
    {
        $flour = new IngredientBuilder()->isFlour()->withCreator($author, true)->build();
        $this->persister->saveIngredient($flour);
        $recipe = new RecipeBuilder()
            ->withAuthor($author)
            ->withName($name)
            ->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->asSecret($secret)
            ->asFavorite($favorite)
            ->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
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
     * @param array<string, string> $headers
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

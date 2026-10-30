<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
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
 * La recette secrète (ADR 0033, REC-001) de bout en bout, avec les vrais bus, la vraie base, le vrai voter et la vraie sécurité : une recette
 * secrète n'est vue que de son auteur. Pour tout autre utilisateur, ADMINISTRATEUR COMPRIS, elle n'est ni dans « Recettes des autres » (liste,
 * total, recherche), ni lisible, ni copiable, ni planifiable : `404`, jamais `403`, comme une recette qui n'existe pas. Les copies déjà faites
 * restent à leurs auteurs. Seul l'auteur change le drapeau.
 */
class SecretRecipeApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $author;
    private User $other;
    private User $admin;
    private Ingredient $flour;

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
        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->author, true)->build();
        $this->persister->saveIngredient($this->flour);
    }

    public function testASecretRecipeIsNeitherInTheListOfOtherPeopleNorInItsTotalNorFoundByItsName(): void
    {
        // Given : une recette secrète et une ordinaire du même auteur, de noms que la même recherche trouverait
        $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $public = $this->savedRecipe($this->author, 'Tarte ordinaire');

        // When
        $this->request('GET', '/api/v1/recipes/other-people', $this->other);
        $list = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people?q=' . rawurlencode('Tarte secrète'), $this->other);
        $exactName = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people?q=tarte', $this->other);
        $search = $this->jsonBody();

        // Then : ni dans la liste, ni dans le total, ni dans la recherche, par son nom exact ou non
        $this->assertSame([[$public->getId()], 1], [array_column($list['member'], 'id'), $list['totalItems']]);
        $this->assertSame([[], 0], [$exactName['member'], $exactName['totalItems']]);
        $this->assertSame([[$public->getId()], 1], [array_column($search['member'], 'id'), $search['totalItems']]);
    }

    public function testAnotherUserReadsASecretRecipeAsNotFoundWithTheAnswerOfAnUnknownRecipe(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);

        // When
        $this->request('GET', '/api/v1/recipes/' . $secret->getId(), $this->other);
        $answer = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];

        // Then : `404` et le message d'une recette inconnue, jamais `403` ni le moindre détail de la recette
        $this->assertSame([404, ['error' => ['message' => RecipeException::notFound($secret->getId())->getMessage()]]], $answer);
        $this->assertStringNotContainsString('Tarte secrète', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorNeitherSeesNorReadsNorCopiesASecretRecipe(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $public = $this->savedRecipe($this->author, 'Tarte ordinaire');
        $recipesBefore = $this->recipeCount();
        $ingredientsBefore = $this->ingredientCount();

        // When
        $this->request('GET', '/api/v1/recipes/other-people', $this->admin);
        $list = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/' . $secret->getId(), $this->admin);
        $read = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/v1/recipes/' . $secret->getId() . '/copy', $this->admin);
        $copy = $this->client->getResponse()->getStatusCode();

        // Then : aucun passe-droit ; rien n'est copié, pas même un ingrédient
        $this->assertSame([[$public->getId()], 1], [array_column($list['member'], 'id'), $list['totalItems']]);
        $this->assertSame([404, 404], [$read, $copy]);
        $this->assertSame([$recipesBefore, $ingredientsBefore], [$this->recipeCount(), $this->ingredientCount()]);
    }

    public function testCopyingASecretRecipeOfAnotherIsNotFoundAndWritesNothing(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $recipesBefore = $this->recipeCount();
        $ingredientsBefore = $this->ingredientCount();

        // When
        $this->request('POST', '/api/v1/recipes/' . $secret->getId() . '/copy', $this->other);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => RecipeException::notFound($secret->getId())->getMessage()]], $this->jsonBody());
        $this->assertSame([$recipesBefore, $ingredientsBefore], [$this->recipeCount(), $this->ingredientCount()]);
    }

    public function testTheAuthorStillReadsListsEditsCopiesAndPlansHisSecretRecipe(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);

        // When : il la lit
        $this->request('GET', '/api/v1/recipes/' . $secret->getId(), $this->author);
        $read = $this->jsonBody();
        $etag = $this->client->getResponse()->headers->get('ETag');
        // Et la retrouve dans ses recettes, avec sa pastille
        $this->request('GET', '/api/v1/recipe-types/plat/recipes', $this->author);
        $mine = $this->jsonBody();
        // Et ne la voit pas dans celles des autres
        $this->request('GET', '/api/v1/recipes/other-people', $this->author);
        $others = $this->jsonBody();

        // Then
        $this->assertSame([true, 'Tarte secrète'], [$read['secret'], $read['name']]);
        $this->assertSame([[$secret->getId(), 'Tarte secrète', true]], array_map(static fn(array $recipe): array => [$recipe['id'], $recipe['name'], $recipe['secret']], $mine['member']));
        $this->assertSame([[], 0], [$others['member'], $others['totalItems']]);

        // When : il la modifie (sans toucher au choix), la copie et la planifie
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->author, $this->body('Tarte secrète revue'), ['HTTP_IF_MATCH' => $etag]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/recipes/' . $secret->getId() . '/copy', $this->author);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copyId = basename($this->client->getResponse()->headers->get('Location'));
        $this->savedActiveList($this->author);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $secret->getId(), $this->author);

        // Then : tout passe, et elle est toujours secrète ; sa copie, elle, est une recette ordinaire
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->recipes()->findOneById($secret->getId())->isSecret());
        $this->assertFalse($this->recipes()->findOneById($copyId)->isSecret());
    }

    public function testAnotherUserCannotPlanASecretRecipeEitherAndTheListStaysEmpty(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $listId = $this->savedActiveList($this->other);

        // When
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $secret->getId(), $this->other);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM meal WHERE shopping_list = ?', $listId));
    }

    public function testACopyAlreadyMadeStaysToItsCopierWhenTheOriginalBecomesSecret(): void
    {
        // Given : l'autre copie une recette ordinaire
        $original = $this->savedRecipe($this->author, 'Tarte ordinaire');
        $this->request('POST', '/api/v1/recipes/' . $original->getId() . '/copy', $this->other);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copyId = basename($this->client->getResponse()->headers->get('Location'));

        // When : l'auteur de l'original la rend secrète
        $this->request('GET', '/api/v1/recipes/' . $original->getId(), $this->author);
        $this->request('PUT', '/api/v1/recipes/' . $original->getId(), $this->author, ['secret' => true] + $this->body('Tarte ordinaire'), ['HTTP_IF_MATCH' => $this->client->getResponse()->headers->get('ETag')]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : l'original n'existe plus pour l'autre, sa copie à lui n'a pas bougé
        $this->request('GET', '/api/v1/recipes/' . $original->getId(), $this->other);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->other);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonBody()['secret']);
        $this->request('GET', '/api/v1/recipe-types/plat/recipes', $this->other);
        $this->assertSame([$copyId], array_column($this->jsonBody()['member'], 'id'));
        // Ni lui ni personne d'autre ne la retrouve dans les recettes des autres
        $this->request('GET', '/api/v1/recipes/other-people', $this->other);
        $this->assertSame([[], 0], [$this->jsonBody()['member'], $this->jsonBody()['totalItems']]);
    }

    public function testACopyOfAnotherUsersRecipeIsNeverSecretAndHisOwnChoiceIsFree(): void
    {
        // Given : la copie d'une recette ordinaire
        $original = $this->savedRecipe($this->author, 'Tarte ordinaire');
        $this->request('POST', '/api/v1/recipes/' . $original->getId() . '/copy', $this->other);
        $copyId = basename($this->client->getResponse()->headers->get('Location'));

        // Then : ordinaire
        $this->assertFalse($this->recipes()->findOneById($copyId)->isSecret());

        // When : son copieur la rend secrète : c'est à lui (ses lignes sont celles de SES ingrédients)
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->other);
        $copierSlug = $this->jsonBody()['rows'][0]['ingredientSlug'];
        $this->request('PUT', '/api/v1/recipes/' . $copyId, $this->other, ['secret' => true, 'rows' => [['ingredientSlug' => $copierSlug, 'unitSlug' => 'gramme', 'quantity' => 250]]] + $this->body('Tarte ordinaire'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->recipes()->findOneById($copyId)->isSecret());
    }

    public function testThePutSwitchesTheFlagAndAnOmittedOrNullFieldChangesNothing(): void
    {
        // Given : une recette ordinaire
        $recipe = $this->savedRecipe($this->author, 'Tarte');
        $id = $recipe->getId();

        // When & Then : le choix de l'auteur ne change que lorsqu'il est envoyé
        $this->put($id, ['secret' => true]);
        $this->assertSame([true], [$this->recipes()->findOneById($id)->isSecret()]);
        $this->put($id, []);
        $this->assertSame([true], [$this->recipes()->findOneById($id)->isSecret()], 'un ancien client, sans le champ, ne remet jamais le choix à zéro');
        $this->put($id, ['secret' => null]);
        $this->assertSame([true], [$this->recipes()->findOneById($id)->isSecret()], '`null` vaut « absent »');
        $this->put($id, ['secret' => false]);
        $this->assertSame([false], [$this->recipes()->findOneById($id)->isSecret()]);
        $this->put($id, []);
        $this->assertSame([false], [$this->recipes()->findOneById($id)->isSecret()]);
        // Chaque écriture a augmenté la version, comme pour les autres champs
        $this->assertSame(6, $this->recipes()->findOneById($id)->getVersion());
    }

    public function testASecretChoiceHonoursTheIfMatchVersionLikeTheOtherFields(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte');
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->author);
        $etag = $this->client->getResponse()->headers->get('ETag');
        $this->put($recipe->getId(), ['secret' => true], ['HTTP_IF_MATCH' => $etag]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : la même version, périmée
        $this->put($recipe->getId(), ['secret' => false], ['HTTP_IF_MATCH' => $etag]);

        // Then : refusé, et le choix n'a pas bougé
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->recipes()->findOneById($recipe->getId())->isSecret());
    }

    public function testCreatesASecretRecipeAndByDefaultAnOrdinaryOne(): void
    {
        // When
        $this->request('POST', '/api/v1/recipes', $this->author, ['secret' => true] + $this->body('Tarte secrète'));
        $secretId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('POST', '/api/v1/recipes', $this->author, $this->body('Tarte ordinaire'));
        $ordinaryId = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('POST', '/api/v1/recipes', $this->author, ['secret' => null] + $this->body('Tarte nulle'));
        $nullId = basename($this->client->getResponse()->headers->get('Location'));

        // Then
        $this->assertSame([true, false, false], [$this->recipes()->findOneById($secretId)->isSecret(), $this->recipes()->findOneById($ordinaryId)->isSecret(), $this->recipes()->findOneById($nullId)->isSecret()]);
        $this->request('GET', '/api/v1/recipes/other-people', $this->other);
        $visible = array_column($this->jsonBody()['member'], 'id');
        sort($visible);
        $expected = [$ordinaryId, $nullId];
        sort($expected);
        $this->assertSame($expected, $visible);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notBooleans(): iterable
    {
        yield 'texte' => ['oui'];
        yield 'texte false' => ['false'];
        yield 'entier 1' => [1];
        yield 'entier 0' => [0];
        yield 'liste' => [[]];
    }

    #[DataProvider('notBooleans')]
    public function testANonBooleanSecretIsRefusedWithoutAnyConversionAndNothingIsWritten(mixed $secret): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Tarte', secret: true);
        $recipesBefore = $this->recipeCount();

        // When
        $this->request('POST', '/api/v1/recipes', $this->author, ['secret' => $secret] + $this->body('Tarte neuve'));
        $created = $this->client->getResponse()->getStatusCode();
        $this->put($recipe->getId(), ['secret' => $secret]);
        $updated = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([422, 422], [$created, $updated]);
        $this->assertSame($recipesBefore, $this->recipeCount());
        $reloaded = $this->recipes()->findOneById($recipe->getId());
        $this->assertSame([true, 1], [$reloaded->isSecret(), $reloaded->getVersion()]);
    }

    public function testAnotherUserWritingASecretRecipeGetsNotFoundWhereAnOrdinaryOneGivesForbidden(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $public = $this->savedRecipe($this->author, 'Tarte ordinaire');

        // When & Then : on ne révèle pas l'existence d'une recette secrète ; une ordinaire reste refusée en `403` comme avant
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->other, $this->body('Pirate'));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipes/' . $secret->getId(), $this->other);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->other, $this->body('Pirate'));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/recipes/' . $public->getId(), $this->other);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Tarte secrète', $this->recipes()->findOneById($secret->getId())->getName());
    }

    public function testAnAdministratorKeepsEditingAndDeletingButCannotChangeTheChoiceOfAnotherAuthor(): void
    {
        // Given : l'administrateur garde, sur les recettes des autres, le droit de modifier et de supprimer qu'il avait
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $public = $this->savedRecipe($this->author, 'Tarte ordinaire');

        // When : il envoie le choix actuel, ou n'en envoie pas : une modification ordinaire
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->admin, ['secret' => true] + $this->body('Tarte secrète corrigée'));
        $sameValue = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->admin, $this->body('Tarte ordinaire corrigée'));
        $omitted = $this->client->getResponse()->getStatusCode();
        // Mais il ne rend pas publique la recette secrète d'un autre, ni ne cache la sienne
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->admin, ['secret' => false] + $this->body('Tarte secrète publiée'));
        $publishes = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->admin, ['secret' => true] + $this->body('Tarte ordinaire cachée'));
        $hides = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([204, 204], [$sameValue, $omitted]);
        $this->assertSame([403, ['error' => ['message' => RecipeException::SECRET_FORBIDDEN]]], $publishes);
        $this->assertSame(403, $hides);
        $reloaded = $this->recipes();
        $this->assertSame(['Tarte secrète corrigée', true, 'Tarte ordinaire corrigée', false], [
            $reloaded->findOneById($secret->getId())->getName(), $reloaded->findOneById($secret->getId())->isSecret(),
            $reloaded->findOneById($public->getId())->getName(), $reloaded->findOneById($public->getId())->isSecret(),
        ]);

        // When : il supprime (comportement inchangé)
        $this->request('DELETE', '/api/v1/recipes/' . $secret->getId(), $this->admin);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    private function put(string $id, array $extra, array $headers = []): void
    {
        $this->request('PUT', '/api/v1/recipes/' . $id, $this->author, $extra + $this->body('Tarte'), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $name): array
    {
        return [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [['ingredientSlug' => $this->flour->getSlug(), 'unitSlug' => 'gramme', 'quantity' => 250]],
        ];
    }

    private function savedRecipe(User $author, string $name, bool $secret = false): Recipe
    {
        $recipe = new RecipeBuilder()
            ->withAuthor($author)
            ->withName($name)
            ->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($this->flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->asSecret($secret)
            ->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedActiveList(User $owner): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
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
     * @param array<string, string> $headers en-têtes de plus, à la manière de `server` (`HTTP_IF_MATCH`)
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

    private function recipes(): RecipeRepository
    {
        $this->entityManager->clear();

        return static::getContainer()->get(RecipeRepository::class);
    }

    private function recipeCount(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM recipe');
    }

    private function ingredientCount(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM ingredient');
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}

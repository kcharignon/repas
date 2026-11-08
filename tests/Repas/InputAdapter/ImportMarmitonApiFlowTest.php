<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Tests\Helper\Builder\ConversionBuilder;
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
use Repas\Tests\Helper\MarmitonInMemoryPageReader;
use Repas\Tests\Helper\MarmitonPages;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * L'import d'une recette Marmiton (ADR 0037, MAR-003) de bout en bout : les vrais bus, la vraie base, le vrai voter, les vrais limiteurs, les
 * vraies erreurs HTTP ; seul le lecteur de page est un double (`MarmitonInMemoryPageReader` : aucun test automatique n'appelle Marmiton).
 * La recette neutre est visible de tous, qu'aucun compte ne modifie (administrateur compris), et chaque compte reçoit SA copie.
 */
class ImportMarmitonApiFlowTest extends DatabaseTestCase
{
    private const string URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';
    private const string CANONICAL = 'https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx';

    private KernelBrowser $client;
    private DataPersister $persister;
    private MarmitonInMemoryPageReader $reader;
    private User $alice;
    private User $bob;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le double du lecteur survit aux requêtes : le noyau n'est pas redémarré entre deux appels
        $this->client->disableReboot();
        $this->reader = new MarmitonInMemoryPageReader()->willRead('22082', new MarmitonRecipePage(self::CANONICAL, MarmitonPages::EXAMPLE_TITLE, '6 personnes', 'Dessert', MarmitonPages::EXAMPLE_LINES));
        static::getContainer()->set(MarmitonPageReader::class, $this->reader);

        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isMiscellaneous()->build());
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isCentiliter(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isCoffeeSpoon(), new UnitBuilder()->withSlug('pincee')->withName('pincée')] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->alice = $this->savedUser();
        $this->bob = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
    }

    // --- Importer ----------------------------------------------------------------------------------------------------------------------

    public function testTheFirstImportCreatesTheCopyWith201AndALocationTheFicheReadsWithItsLinkOfOrigin(): void
    {
        // When
        $this->import($this->alice);
        $location = $this->client->getResponse()->headers->get('Location');

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', (string) $this->client->getResponse()->getContent());
        $this->assertMatchesRegularExpression('#^/api/v1/recipes/[0-9a-f-]{36}$#', (string) $location);

        $this->request('GET', (string) $location, $this->alice);
        $fiche = $this->jsonBody();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['Tarte au citron meringuée', 6, 'dessert', false, false, self::CANONICAL], [$fiche['name'], $fiche['serving'], $fiche['typeSlug'], $fiche['secret'], $fiche['favorite'], $fiche['sourceUrl']]);
        $this->assertCount(13, $fiche['rows']);
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame(self::URL, $this->reader->calls()[0]->fetchUrl());
    }

    public function testImportingTheSameLinkAgainReturns200AndTheSameCopyWithoutWritingAnything(): void
    {
        // Given
        $this->import($this->alice);
        $location = $this->client->getResponse()->headers->get('Location');
        $recipes = $this->rows('recipe');
        $ingredients = $this->rows('ingredient');

        // When : le même lien, écrit autrement
        $this->import($this->alice, 'http://marmiton.org/recettes/recette_autre_22082.aspx?x=1');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($location, $this->client->getResponse()->headers->get('Location'));
        $this->assertSame([$recipes, $ingredients, 1], [$this->rows('recipe'), $this->rows('ingredient'), $this->reader->callCount()]);
    }

    public function testASecondAccountCopiesTheKnownNeutralWithoutAnyCallToMarmiton(): void
    {
        // Given
        $this->import($this->alice);

        // When
        $this->import($this->bob);

        // Then : une copie de plus, la même neutre, un seul appel sortant
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame(1, $this->rows('recipe WHERE marmiton_id IS NOT NULL'));
        $this->assertSame(2, $this->rows('recipe WHERE orignal IS NOT NULL'));
        $this->assertSame(3, $this->rows('recipe'));
    }

    public function testNotLoggedInIs401(): void
    {
        // When
        $this->client->request('POST', '/api/v1/recipes/import/marmiton', content: json_encode(['url' => self::URL]), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->reader->callCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans lien' => [[]];
        yield 'lien nul' => [['url' => null]];
        yield 'lien qui est un nombre' => [['url' => 22082]];
        yield 'lien qui est une liste' => [['url' => [self::URL]]];
        yield 'lien de plus de 2 000 caractères' => [['url' => 'https://www.marmiton.org/recettes/recette_' . str_repeat('a', 2000) . '_1.aspx']];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs422AndNothingIsReadNorWritten(?array $body): void
    {
        // When
        $this->request('POST', '/api/v1/recipes/import/marmiton', $this->alice, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('message', $this->jsonBody()['error']);
        $this->assertSame([0, 0], [$this->reader->callCount(), $this->rows('recipe')]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notMarmitonAddresses(): iterable
    {
        yield 'un texte quelconque' => ['pas une adresse'];
        yield 'un autre site' => ['https://example.org/recettes/recette_a_1.aspx'];
        yield 'la recherche de Marmiton' => ['https://www.marmiton.org/recettes/recherche.aspx'];
        yield 'un hôte qui commence comme Marmiton' => ['https://www.marmiton.org.evil.com/recettes/recette_a_1.aspx'];
        yield 'une adresse locale' => ['http://127.0.0.1/recettes/recette_a_1.aspx'];
        yield 'vide' => [''];
    }

    #[DataProvider('notMarmitonAddresses')]
    public function testAnAddressThatIsNotAMarmitonRecipeIs422WithItsMessageAndNothingIsCalled(string $url): void
    {
        // When
        $this->import($this->alice, $url);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'MARMITON_URL_INVALID']], $this->jsonBody());
        $this->assertSame([0, 0], [$this->reader->callCount(), $this->rows('recipe')]);
    }

    /**
     * @return iterable<string, array{MarmitonImportException, int, string}>
     */
    public static function failures(): iterable
    {
        yield 'Marmiton ne connaît pas la recette' => [MarmitonImportException::recipeNotFound(), 422, 'MARMITON_RECIPE_NOT_FOUND'];
        yield 'la page n\'a pas de recette lisible' => [MarmitonImportException::recipeUnreadable(), 422, 'MARMITON_RECIPE_UNREADABLE'];
        yield 'Marmiton ne répond pas' => [MarmitonImportException::unavailable(), 502, 'MARMITON_UNAVAILABLE'];
    }

    #[DataProvider('failures')]
    public function testAFailedReadAnswersItsStatusAndMessageAndWritesNothing(MarmitonImportException $failure, int $status, string $message): void
    {
        // Given
        $this->reader->willFail('22082', $failure);
        $recipes = $this->rows('recipe');
        $ingredients = $this->rows('ingredient');

        // When
        $this->import($this->alice);

        // Then
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
        $this->assertSame([$recipes, $ingredients], [$this->rows('recipe'), $this->rows('ingredient')]);
    }

    public function testAPageWhoseLinesAreAllUnreadableIs422AndLeavesNoIngredientBehind(): void
    {
        // Given
        $this->reader->willRead('22082', new MarmitonRecipePage(self::CANONICAL, 'Tarte', null, null, ['.', '—', '250 g']));

        // When
        $this->import($this->alice);

        // Then
        $this->assertSame([422, ['error' => ['message' => 'MARMITON_RECIPE_UNREADABLE']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame([0, 0], [$this->rows('recipe'), $this->rows('ingredient')]);
    }

    public function testTheTwentyFirstPageReadOfTheHourIs429WithRetryAfterButAKnownNeutralStillCopies(): void
    {
        // Given : vingt recettes différentes importées par Alice (vingt lectures de page)
        for ($id = 1; $id <= 21; $id++) {
            $this->reader->willRead((string) $id, new MarmitonRecipePage(sprintf('https://www.marmiton.org/recettes/recette_plat_%d.aspx', $id), 'Plat ' . $id, null, null, ['1 oeuf']));
        }
        for ($id = 1; $id <= 20; $id++) {
            $this->import($this->alice, sprintf('https://www.marmiton.org/recettes/recette_plat_%d.aspx', $id));
            $this->assertSame(201, $this->client->getResponse()->getStatusCode(), "L'import $id");
        }
        $recipes = $this->rows('recipe');

        // When : une vingt-et-unième page à lire
        $this->import($this->alice, 'https://www.marmiton.org/recettes/recette_plat_21.aspx');

        // Then : 429, le délai d'attente, aucune page lue de plus, rien d'écrit
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'MARMITON_IMPORT_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(20, $this->reader->callCount());
        $this->assertSame($recipes, $this->rows('recipe'));

        // When : copier une neutre déjà connue ne lit aucune page et passe, même plafond atteint
        $this->import($this->alice, sprintf('https://www.marmiton.org/recettes/recette_plat_%d.aspx', 1));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'Alice a déjà sa copie du premier.');
        $this->import($this->bob, 'https://www.marmiton.org/recettes/recette_plat_1.aspx');
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(20, $this->reader->callCount());
        $this->assertSame($recipes + 1, $this->rows('recipe'));
    }

    // --- La copie est à la personne, la neutre n'est à personne --------------------------------------------------------------------------

    public function testTheCopyIsAnOrdinaryRecipeItsAuthorChangesFavoritesAndHides(): void
    {
        // Given
        $copyId = $this->importedId($this->alice);

        // When : elle la modifie, la met en favoris, la rend secrète
        $this->request('GET', "/api/v1/recipes/$copyId", $this->alice);
        $etag = $this->client->getResponse()->headers->get('ETag');
        $body = $this->jsonBody();
        $this->request('PUT', "/api/v1/recipes/$copyId", $this->alice, [
            'name' => 'Ma tarte', 'serving' => 4, 'typeSlug' => 'dessert', 'secret' => true,
            'rows' => array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], array_slice($body['rows'], 0, 2)),
        ], ['HTTP_IF_MATCH' => $etag]);
        $updated = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', "/api/v1/recipes/$copyId/favorite", $this->alice, ['favorite' => true]);
        $favorite = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([204, 204], [$updated, $favorite]);
        $this->request('GET', "/api/v1/recipes/$copyId", $this->alice);
        $this->assertSame(['Ma tarte', 4, true, true, self::CANONICAL], [$this->jsonBody()['name'], $this->jsonBody()['serving'], $this->jsonBody()['secret'], $this->jsonBody()['favorite'], $this->jsonBody()['sourceUrl']]);
    }

    public function testNoOrdinaryUserWritesTheNeutralAndNobodyMakesItSecretOrFavorite(): void
    {
        // Given
        $this->import($this->alice);
        $neutralId = $this->neutralId();
        $body = ['name' => 'Piratée', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [], 'secret' => true];
        $before = $this->neutralSnapshot();

        foreach ([$this->bob, $this->alice] as $who) {
            // When & Then : modifier, supprimer, mettre en favoris, rendre secrète : refusé à tout utilisateur ordinaire, la recette existe donc jamais `404`
            $this->request('PUT', "/api/v1/recipes/$neutralId", $who, $body);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'PUT');
            $this->request('PUT', "/api/v1/recipes/$neutralId", $who, ['secret' => false] + $body);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'PUT visible');
            $this->request('DELETE', "/api/v1/recipes/$neutralId", $who);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'DELETE');
            $this->request('PUT', "/api/v1/recipes/$neutralId/favorite", $who, ['favorite' => true]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'favori');
        }
        // L'administrateur, lui, la modifie et la supprime (`ModifyOwnerlessRecipeApiFlowTest`) ; la rendre secrète ou favorite reste refusé : ce n'est le choix de personne
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $rows = array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], $this->jsonBody()['rows']);
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['rows' => $rows] + $body);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'PUT secret par l\'administrateur');
        $this->request('PUT', "/api/v1/recipes/$neutralId/favorite", $this->admin, ['favorite' => true]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'favori par l\'administrateur');
        $this->assertSame($before, $this->neutralSnapshot(), 'La neutre est telle quelle : même version, même nom, mêmes lignes.');
    }

    public function testNobodyPlansTheNeutralDirectlyOrTakesItOutOfAPlan(): void
    {
        // Given : chacun a une liste en cours
        $this->import($this->alice);
        $neutralId = $this->neutralId();
        foreach ([$this->bob, $this->admin] as $who) {
            static::getContainer()->get(ShoppingListRepository::class)->save(new ShoppingListBuilder()->withOwner($who)->build());
        }
        $rows = $this->rows('shopping_list_row');

        foreach ([$this->bob, $this->admin] as $who) {
            // When : la planifier directement est refusé (la recette d'un autre n'est pas planifiable), la retirer ne retire rien
            $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$neutralId", $who);
            $plan = $this->client->getResponse()->getStatusCode();
            $this->request('DELETE', "/api/v1/shopping-lists/active/recipes/$neutralId", $who);
            $unplan = $this->client->getResponse()->getStatusCode();

            // Then
            $this->assertSame(404, $plan);
            $this->assertLessThan(300, $unplan);
        }
        $this->assertSame($rows, $this->rows('shopping_list_row'));
        $this->assertSame(0, $this->rows('meal'));
    }

    public function testEveryoneReadsTheNeutralAndItIsNeverTheFavoriteOfAnyone(): void
    {
        // Given
        $this->import($this->alice);
        $neutralId = $this->neutralId();

        foreach ([$this->bob, $this->admin, $this->alice] as $who) {
            // When
            $this->request('GET', "/api/v1/recipes/$neutralId", $who);
            $fiche = $this->jsonBody();

            // Then : lisible par tous, ordinaire, jamais favorite, avec son lien d'origine
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertSame([false, false, self::CANONICAL], [$fiche['secret'], $fiche['favorite'], $fiche['sourceUrl']]);
        }
    }

    public function testTheNeutralIsInTheRecipesOfOthersWithItsBadgeUntilTheAccountHasItsCopy(): void
    {
        // Given
        $this->import($this->alice);
        $neutralId = $this->neutralId();

        // When
        $this->request('GET', '/api/v1/recipes/other-people', $this->bob);
        $bobsList = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people?q=' . rawurlencode('tarte citron'), $this->admin);
        $search = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people', $this->alice);
        $alicesList = $this->jsonBody();

        // Then : pour Bob et pour l'administrateur (liste et recherche), pas pour Alice qui en a la copie ; la copie d'Alice n'y est pas non plus
        $this->assertSame([[$neutralId], 1, [true]], [array_column($bobsList['member'], 'id'), $bobsList['totalItems'], array_column($bobsList['member'], 'fromMarmiton')]);
        $this->assertSame([$neutralId], array_column($search['member'], 'id'));
        $this->assertSame([[], 0], [$alicesList['member'], $alicesList['totalItems']]);

        // When : Bob prend sa copie
        $this->import($this->bob);
        $this->request('GET', '/api/v1/recipes/other-people', $this->bob);

        // Then : elle ne lui est plus proposée
        $this->assertSame([[], 0], [$this->jsonBody()['member'], $this->jsonBody()['totalItems']]);
    }

    public function testAnOrdinaryRecipeIsNotFromMarmiton(): void
    {
        // Given : Alice importe, Bob écrit sa propre recette
        $this->import($this->alice);
        $this->request('GET', '/api/v1/recipes/other-people', $this->bob);
        $this->assertTrue($this->jsonBody()['member'][0]['fromMarmiton']);

        $this->savedRecipe($this->bob, 'Salade');

        // When
        $this->request('GET', '/api/v1/recipes/other-people', $this->alice);

        // Then : « Recettes des autres » d'Alice ne contient que la recette ordinaire de Bob, sans pastille
        $this->assertSame([false], array_column($this->jsonBody()['member'], 'fromMarmiton'));
    }

    public function testTheLinkOfOriginSurvivesACopyOfTheCopy(): void
    {
        // Given : Bob copie la copie d'Alice par la route ordinaire
        $alicesCopy = $this->importedId($this->alice);
        $this->request('POST', "/api/v1/recipes/$alicesCopy/copy", $this->bob);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $bobsCopy = basename((string) $this->client->getResponse()->headers->get('Location'));

        // When
        $this->request('GET', "/api/v1/recipes/$bobsCopy", $this->bob);

        // Then
        $this->assertSame(self::CANONICAL, $this->jsonBody()['sourceUrl']);
    }

    public function testARecipeWithoutAMarmitonOriginHasANullLink(): void
    {
        // Given
        $id = $this->savedRecipe($this->alice, 'Salade');

        // When
        $this->request('GET', "/api/v1/recipes/$id", $this->alice);

        // Then : la clé est toujours écrite
        $this->assertArrayHasKey('sourceUrl', $this->jsonBody());
        $this->assertNull($this->jsonBody()['sourceUrl']);
    }

    public function testTheLinkIsNeverAcceptedFromTheBodyOfARecipe(): void
    {
        // When : un client envoie un lien d'origine à la création, puis à la modification
        $row = ['ingredientSlug' => 'farine' . $this->alice->getId(), 'unitSlug' => 'gramme', 'quantity' => 100];
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withCreator($this->alice, true)->build());
        $this->request('POST', '/api/v1/recipes', $this->alice, ['name' => 'Salade', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [$row], 'sourceUrl' => self::CANONICAL, 'marmitonId' => '1']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $id = basename((string) $this->client->getResponse()->headers->get('Location'));
        $this->request('PUT', "/api/v1/recipes/$id", $this->alice, ['name' => 'Salade', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => [$row], 'sourceUrl' => self::CANONICAL]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : ignoré, la recette n'a ni lien ni tag
        $this->request('GET', "/api/v1/recipes/$id", $this->alice);
        $this->assertNull($this->jsonBody()['sourceUrl']);
        $this->assertSame(0, $this->rows('recipe WHERE marmiton_id IS NOT NULL'));
    }

    public function testTheCopyKeepsTheLinkEvenWhenItsAuthorChangesItAndEveryNameStartsWithACapital(): void
    {
        // Given
        $this->reader->willRead('22082', new MarmitonRecipePage(self::CANONICAL, 'tarte aux pommes : la meilleure recette', null, 'Dessert', ['3 pommes']));

        // When
        $copyId = $this->importedId($this->alice);

        // Then
        $this->request('GET', "/api/v1/recipes/$copyId", $this->alice);
        $this->assertSame('Tarte aux pommes', $this->jsonBody()['name']);
        $this->assertSame('Pommes', $this->jsonBody()['rows'][0]['ingredientName']);
    }

    // --- Aides --------------------------------------------------------------------------------------------------------------------------

    private function import(User $user, string $url = self::URL): void
    {
        $this->request('POST', '/api/v1/recipes/import/marmiton', $user, ['url' => $url]);
    }

    private function savedRecipe(User $author, string $name): string
    {
        $flour = new IngredientBuilder()->isFlour()->withCreator($author, true)->build();
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))->build();
        $this->persister->saveRecipe($recipe);

        return $recipe->getId();
    }

    private function importedId(User $user): string
    {
        $this->import($user);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201]);

        return basename((string) $this->client->getResponse()->headers->get('Location'));
    }

    private function neutralId(): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne("SELECT id FROM recipe WHERE marmiton_id = '22082'");
    }

    /**
     * @return array{array<string, mixed>|false, list<array<string, mixed>>}
     */
    private function neutralSnapshot(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            $connection->fetchAssociative("SELECT id, name, serving, author, type, version, secret, favorite, source_url FROM recipe WHERE marmiton_id = '22082'"),
            $connection->fetchAllAssociative("SELECT rr.id, rr.ingredient, rr.quantity, rr.unit, rr.position FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe WHERE r.marmiton_id = '22082' ORDER BY rr.position"),
        ];
    }

    private function rows(string $tableAndFilter): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM $tableAndFilter");
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
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

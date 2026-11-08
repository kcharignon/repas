<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Repas\Shared\Domain\Tool\UuidGenerator;
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
 * Écrire les recettes qu'une recette utilise (ADR 0040, COMP-002) de bout en bout : les vrais bus, la vraie base, le vrai voter et les vraies erreurs
 * HTTP. Créer, remplacer (`components` absent, `null`, `[]`), les refus avec leur statut et leur message (rien n'est écrit sur un refus), les deux
 * bascules du secret, l'administrateur (qui compare avec l'AUTEUR de la recette et n'a aucun passe-droit sur une recette secrète), la suppression refusée
 * puis permise, les recettes proposables et les recettes qui en utilisent une. Et ce qui est écrit par l'API arrive dans la liste de courses avec les
 * bonnes quantités (COMP-004 l'aplatit).
 *
 * Pour un compte de 4 personnes : « Pâte sablée » pour 6 (Farine 250 g), « Crème » pour 2 (Lait 200 g), « Tarte au citron » pour 4 (Sucre 100 g).
 */
class RecipeComponentsApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $author;
    private User $other;
    private User $admin;
    private Ingredient $flour;
    private Ingredient $sugar;
    private Ingredient $milk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->author = $this->savedUser();
        $this->other = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
        $this->flour = $this->ingredientOf($this->author, 'Farine');
        $this->sugar = $this->ingredientOf($this->author, 'Sucre');
        $this->milk = $this->ingredientOf($this->author, 'Lait');
    }

    // --- Créer, lire, planifier

    public function testARecipeIsCreatedWithItsUsedRecipesReadBackAndPlannedWithTheLinesOfBoth(): void
    {
        // Given : la pâte (6 personnes, 250 g de farine) et la crème (2 personnes, 200 g de lait) existent
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]], serving: 6);
        $cream = $this->savedRecipe($this->author, 'Crème', [[$this->milk, 200]], serving: 2);

        // When : une tarte pour 4 avec 100 g de sucre, qui utilise la pâte 2 fois puis la crème une demi-fois
        $this->request('POST', '/api/v1/recipes', $this->author, $this->body('tarte au citron', [[$this->sugar, 100]], [[$dough, 2], [$cream, 0.5]]));

        // Then : 201, l'identifiant de la recette dans `Location`
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $pie = basename((string) $this->client->getResponse()->headers->get('Location'));
        $this->request('GET', '/api/v1/recipes/' . $pie, $this->author);
        $sheet = $this->jsonBody();
        $this->assertSame('Tarte au citron', $sheet['name']);
        // ses lignes propres ne contiennent QUE le sucre ; les recettes utilisées, dans l'ordre reçu, avec leurs lignes telles qu'écrites
        $this->assertSame([$this->sugar->getSlug()], array_column($sheet['rows'], 'ingredientSlug'));
        $this->assertSame([[$dough->getId(), 2, 6, 250.0], [$cream->getId(), 0.5, 2, 200.0]], array_map(
            static fn(array $component): array => [$component['recipeId'], $component['multiplier'], $component['serving'], (float) $component['rows'][0]['quantity']],
            $sheet['components'],
        ));
        $this->assertSame([[0, 2.0], [1, 0.5]], $this->edges($pie));
        $this->assertSame(2, $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $pie));

        // When : on la planifie pour les 4 personnes du compte
        $list = $this->createdList($this->author);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $pie, $this->author);

        // Then : la liste reçoit le sucre de la tarte ET les lignes des recettes utilisées mises à l'échelle (farine 2 × 250 g, lait 0,5 × 200 g × 4 ÷ 4
        // pour la tarte pour 4 personnes), dans l'unité d'achat : 0,5 kg de farine, 0,1 kg de sucre, 100 g de lait
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = $this->listRows($list);
        $this->assertEqualsWithDelta(0.5, $rows[$this->flour->getSlug()], 1e-9);
        $this->assertEqualsWithDelta(0.1, $rows[$this->sugar->getSlug()], 1e-9);
        $this->assertEqualsWithDelta(0.1, $rows[$this->milk->getSlug()], 1e-9);
        $this->assertCount(3, $rows);
        // un seul repas, celui de la tarte : une recette utilisée n'est jamais un repas du parent
        $this->assertSame([$pie], $this->entityManager->getConnection()->fetchFirstColumn('SELECT recipe FROM meal WHERE shopping_list = ?', [$list]));
    }

    public function testARecipeMadeOnlyOfUsedRecipesIsCreatedAndAZeroLineBodyWithoutThemIsRefused(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]], serving: 6);

        // When : zéro ligne, une recette utilisée
        $this->request('POST', '/api/v1/recipes', $this->author, $this->body('Pâte à tout faire', [], [[$dough, 1]]));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $only = basename((string) $this->client->getResponse()->headers->get('Location'));
        $this->request('GET', '/api/v1/recipes/' . $only, $this->author);
        $this->assertSame([[], [$dough->getId()]], [$this->jsonBody()['rows'], array_column($this->jsonBody()['components'], 'recipeId')]);

        // When : un ancien client envoie `rows: []` seul, ou avec `components: []`
        $recipesBefore = $this->scalar('SELECT COUNT(*) FROM recipe');
        $withoutAnything = $this->answer('POST', '/api/v1/recipes', $this->author, $this->body('Rien', [], null));
        $withEmptyComponents = $this->answer('POST', '/api/v1/recipes', $this->author, $this->body('Rien', [], []));

        // Then : `422 RECIPE_EMPTY` et rien n'est écrit
        $this->assertSame([422, ['error' => ['message' => RecipeException::EMPTY]]], [$withoutAnything['status'], $withoutAnything['json']]);
        $this->assertSame([422, ['error' => ['message' => RecipeException::EMPTY]]], [$withEmptyComponents['status'], $withEmptyComponents['json']]);
        $this->assertSame($recipesBefore, $this->scalar('SELECT COUNT(*) FROM recipe'));
    }

    public function testASecretRecipeUsesBothOrdinaryAndSecretRecipesAndAnOrdinaryOneOnlyOrdinaryOnes(): void
    {
        // Given
        $ordinary = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $secret = $this->savedRecipe($this->author, 'Crème secrète', [[$this->milk, 200]], secret: true);

        // When : une recette secrète utilise les deux
        $this->request('POST', '/api/v1/recipes', $this->author, ['secret' => true] + $this->body('Tarte secrète', [[$this->sugar, 100]], [[$ordinary, 1], [$secret, 1]]));
        $created = $this->client->getResponse()->getStatusCode();
        // When : une recette ordinaire utilise la secrète
        $refused = $this->answer('POST', '/api/v1/recipes', $this->author, $this->body('Tarte ordinaire', [[$this->sugar, 100]], [[$ordinary, 1], [$secret, 1]]));

        // Then
        $this->assertSame(201, $created);
        $this->assertSame([409, ['error' => ['message' => RecipeException::COMPONENT_SECRET]]], [$refused['status'], $refused['json']]);
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM recipe WHERE name LIKE 'Tarte%'"));
    }

    // --- Remplacer : absent, null, [], remplacement, position

    public function testAnOldClientSendingRowsOnlyAndAnUnrelatedWriteNeverEraseTheComponents(): void
    {
        // Given : une tarte qui utilise la pâte et la crème
        [$dough, $cream, $pie] = $this->pieWithTwoUsedRecipes();
        $edgesBefore = $this->edgesWithIds($pie->getId());
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->author);
        $etag = (string) $this->client->getResponse()->headers->get('ETag');
        $version = $this->jsonBody()['version'];

        // When : le `PUT` d'un ancien client, sans `components`, avec `If-Match`
        $old = $this->body('Tarte renommée', [[$this->sugar, 150]], null);
        $this->assertArrayNotHasKey('components', $old);
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $old, ['HTTP_IF_MATCH' => $etag]);

        // Then : 204, la version a monté, les mêmes arêtes (mêmes identifiants, multiplicateurs, positions) et le même compte
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($version + 1, $this->scalar('SELECT version FROM recipe WHERE id = ?', $pie->getId()));
        $this->assertSame($edgesBefore, $this->edgesWithIds($pie->getId()));
        $this->assertSame(2, $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $pie->getId()));

        // When : `components: null` (comme absent), puis le choix « favori » et « secret » qui enregistrent aussi la recette
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 150]], null) + ['components' => null]);
        $nullStatus = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId() . '/favorite', $this->author, ['favorite' => true]);
        $favoriteStatus = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([204, 204], [$nullStatus, $favoriteStatus]);
        $this->assertSame($edgesBefore, $this->edgesWithIds($pie->getId()));
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->author);
        $this->assertSame([$dough->getId(), $cream->getId()], array_column($this->jsonBody()['components'], 'recipeId'));
    }

    public function testAnEmptyListRemovesEveryComponentAndTheRecipeStaysUsableWithItsOwnRows(): void
    {
        // Given
        [$dough, $cream, $pie] = $this->pieWithTwoUsedRecipes();

        // When
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], []));

        // Then : plus d'arête, `component_count` à zéro, les deux recettes utilisées intactes
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[], 0], [$this->edges($pie->getId()), $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $pie->getId())]);
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe WHERE id IN (?, ?)', $dough->getId(), $cream->getId()));
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->author);
        $this->assertSame([], $this->jsonBody()['components']);
        // et elle est de nouveau utilisable par une autre
        $this->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $dough->getId(), $this->author);
        $this->assertContains($pie->getId(), array_column($this->jsonBody()['member'], 'id'));
    }

    public function testAReplacementKeepsTheEdgeOfAPairItAlreadyHasChangesTheOrderAndTheMultiplier(): void
    {
        // Given : la tarte utilise la pâte (×2, position 0) puis la crème (×0,5, position 1)
        [$dough, $cream, $pie] = $this->pieWithTwoUsedRecipes();
        $doughEdge = $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe_component WHERE recipe = ? AND component = ?', [$pie->getId(), $dough->getId()]);
        $third = $this->savedRecipe($this->author, 'Chantilly', [[$this->milk, 100]]);

        // When : la crème d'abord (×3), puis la pâte (×1,25), puis la chantilly (nouvelle)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[$cream, 3], [$dough, 1.25], [$third, 1]]));

        // Then : l'ordre du tableau est la position, les multiplicateurs sont ceux reçus, et la pâte garde l'identifiant de son arête
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[0, 3.0], [1, 1.25], [2, 1.0]], $this->edges($pie->getId()));
        $this->assertSame(
            [$cream->getId(), $dough->getId(), $third->getId()],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT component FROM recipe_component WHERE recipe = ? ORDER BY position', [$pie->getId()]),
        );
        $this->assertSame($doughEdge, $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe_component WHERE recipe = ? AND component = ?', [$pie->getId(), $dough->getId()]));
        $this->assertSame(3, $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $pie->getId()));

        // When : on n'en garde qu'une
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[$third, 2]]));

        // Then : les deux autres arêtes sont parties, les recettes elles-mêmes restent
        $this->assertSame([[0, 2.0]], $this->edges($pie->getId()));
        $this->assertSame(1, $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $pie->getId()));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM recipe WHERE id = ?', $dough->getId()));
    }

    public function testAMultiplierOnTheBoundsIsAccepted(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);

        // When & Then : 0,01 et 100 sont dans les bornes
        foreach ([0.01, 100, 1] as $multiplier) {
            $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[$dough, $multiplier]]));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "multiplicateur $multiplier");
            $this->assertSame([[0, (float) $multiplier]], $this->edges($pie->getId()));
        }
    }

    // --- Les refus : statut, message, rien n'est écrit

    /**
     * @return iterable<string, array{string}> un nom de cas, rejoué par `refusal()`
     */
    public static function refusals(): iterable
    {
        yield 'recette utilisée inconnue' => ['unknown'];
        yield "recette utilisée d'un autre compte" => ['of-another'];
        yield "recette utilisée secrète d'un autre compte" => ['secret-of-another'];
        yield 'la recette elle-même' => ['self'];
        yield 'la même recette deux fois' => ['duplicate'];
        yield 'multiplicateur sous la borne' => ['multiplier-low'];
        yield 'multiplicateur au-dessus de la borne' => ['multiplier-high'];
        yield 'multiplicateur de mauvais type' => ['multiplier-text'];
        yield 'onze recettes utilisées (avant toute lecture)' => ['eleven'];
        yield 'la recette est déjà utilisée par une autre' => ['parent-is-used'];
        yield 'la recette utilisée a ses propres composants' => ['component-has-components'];
        yield 'une recette secrète pour une recette ordinaire' => ['secret-for-ordinary'];
    }

    #[DataProvider('refusals')]
    public function testEveryRefusalHasItsStatusAndMessageAndWritesNothing(string $case): void
    {
        // Given : la tarte (ordinaire, une ligne, aucun composant) et ce qu'il faut pour chaque cas
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $unknownId = UuidGenerator::new();
        [$components, $expected] = match ($case) {
            'unknown' => [[['recipeId' => $unknownId, 'multiplier' => 1]], [404, RecipeException::notFound($unknownId)->getMessage()]],
            'of-another' => (function (): array {
                $foreign = $this->savedRecipe($this->other, 'Pâte du voisin', [[$this->ingredientOf($this->other, 'Farine'), 250]]);

                return [[['recipeId' => $foreign->getId(), 'multiplier' => 1]], [404, RecipeException::notFound($foreign->getId())->getMessage()]];
            })(),
            'secret-of-another' => (function (): array {
                $foreign = $this->savedRecipe($this->other, 'Pâte secrète', [[$this->ingredientOf($this->other, 'Farine'), 250]], secret: true);

                return [[['recipeId' => $foreign->getId(), 'multiplier' => 1]], [404, RecipeException::notFound($foreign->getId())->getMessage()]];
            })(),
            'self' => [[['recipeId' => $pie->getId(), 'multiplier' => 1]], [422, RecipeException::COMPONENT_SELF]],
            'duplicate' => [[['recipeId' => $dough->getId(), 'multiplier' => 1], ['recipeId' => $dough->getId(), 'multiplier' => 2]], [422, RecipeException::COMPONENT_DUPLICATE]],
            'multiplier-low' => [[['recipeId' => $dough->getId(), 'multiplier' => 0.0099]], [422, 'components[0].multiplier: ' . PayloadMessages::COMPONENT_MULTIPLIER]],
            'multiplier-high' => [[['recipeId' => $dough->getId(), 'multiplier' => 100.01]], [422, 'components[0].multiplier: ' . PayloadMessages::COMPONENT_MULTIPLIER]],
            'multiplier-text' => [[['recipeId' => $dough->getId(), 'multiplier' => '2']], [422, 'components[0].multiplier: ' . PayloadMessages::COMPONENT_MULTIPLIER]],
            // onze identifiants inventés : le nombre est jugé avant toute lecture (sinon le premier serait un 404)
            'eleven' => [array_map(static fn(): array => ['recipeId' => UuidGenerator::new(), 'multiplier' => 1], range(1, 11)), [422, RecipeException::COMPONENT_LIMIT_REACHED]],
            'parent-is-used' => (function () use ($dough, $pie): array {
                // une « menu » utilise déjà la tarte : la tarte ne peut plus avoir de composants
                $this->savedRecipe($this->author, 'Menu', [[$this->milk, 100]], components: [[$pie, 1]]);

                return [[['recipeId' => $dough->getId(), 'multiplier' => 1]], [422, RecipeException::COMPONENT_CHAIN]];
            })(),
            'component-has-components' => (function () use ($dough): array {
                $composed = $this->savedRecipe($this->author, 'Pâte aux amandes', [[$this->sugar, 50]], components: [[$dough, 1]]);

                return [[['recipeId' => $composed->getId(), 'multiplier' => 1]], [422, RecipeException::COMPONENT_CHAIN]];
            })(),
            'secret-for-ordinary' => (function (): array {
                $secret = $this->savedRecipe($this->author, 'Crème secrète', [[$this->milk, 100]], secret: true);

                return [[['recipeId' => $secret->getId(), 'multiplier' => 1]], [409, RecipeException::COMPONENT_SECRET]];
            })(),
            default => throw new \LogicException("Cas inconnu : $case"),
        };
        $before = $this->snapshot($pie->getId());

        // When : le remplacement, puis la même chose à la création
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte modifiée', [[$this->sugar, 999]]) + ['components' => $components]);
        $put = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']];
        $recipes = $this->scalar('SELECT COUNT(*) FROM recipe');
        // À la création, la recette n'existe pas encore : l'utiliser « elle-même » ou la dire « déjà utilisée » n'a pas de sens, la nouvelle recette peut
        // très bien utiliser la tarte ; les autres refus sont les mêmes
        $post = $expected;
        if (!in_array($case, ['self', 'parent-is-used'], true)) {
            $this->request('POST', '/api/v1/recipes', $this->author, $this->body('Nouvelle tarte', [[$this->sugar, 999]]) + ['components' => $components]);
            $post = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']];
        }

        // Then : le statut et le message attendus aux deux endroits, rien n'est écrit
        $this->assertSame($expected, $put, 'PUT');
        $this->assertSame($expected, $post, 'POST');
        $this->assertSame($before, $this->snapshot($pie->getId()), 'La recette est telle quelle : même version, même nom, mêmes lignes, mêmes arêtes.');
        $this->assertSame($recipes, $this->scalar('SELECT COUNT(*) FROM recipe'), 'Aucune recette créée sur un refus.');
    }

    public function testAnotherAccountsRecipeAndASecretOneOfAnotherAreIndistinguishableFromAnUnknownId(): void
    {
        // Given
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $foreign = $this->savedRecipe($this->other, 'Pâte du voisin', [[$this->ingredientOf($this->other, 'Farine'), 250]]);
        $foreignSecret = $this->savedRecipe($this->other, 'Pâte secrète', [[$this->ingredientOf($this->other, 'Beurre'), 250]], secret: true);
        $unknownId = UuidGenerator::new();

        // When
        $answers = [];
        foreach (['inconnue' => $unknownId, "d'un autre" => $foreign->getId(), "secrète d'un autre" => $foreignSecret->getId()] as $label => $id) {
            $answers[$label] = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[['id' => $id], 1]]), $id);
        }

        // Then : le même statut, le même corps (à l'identifiant près), les mêmes en-têtes
        $this->assertSame(404, $answers['inconnue']['status']);
        $this->assertSame($answers['inconnue'], $answers["d'un autre"]);
        $this->assertSame($answers['inconnue'], $answers["secrète d'un autre"]);
        $this->assertStringNotContainsString('secrète', $answers["secrète d'un autre"]['body']);
    }

    public function testTheControlsAreJudgedInTheOrderOfTheAdrWhateverTheNumberOfFaults(): void
    {
        // Given : une tarte qui ne peut rien utiliser (déjà utilisée par un menu) et une recette secrète pour une recette ordinaire
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $secret = $this->savedRecipe($this->author, 'Crème secrète', [[$this->milk, 100]], secret: true);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $this->savedRecipe($this->author, 'Menu', [[$this->milk, 100]], components: [[$pie, 1]]);
        $unknownId = UuidGenerator::new();

        // When & Then : le nombre avant la lecture (11 inconnus : 422, pas 404)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => array_map(static fn(): array => ['recipeId' => UuidGenerator::new(), 'multiplier' => 1], range(1, 11))]);
        $this->assertSame([422, RecipeException::COMPONENT_LIMIT_REACHED], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
        // les doublons avant la lecture (deux fois un inconnu : 422, pas 404)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => [['recipeId' => $unknownId, 'multiplier' => 1], ['recipeId' => $unknownId, 'multiplier' => 1]]]);
        $this->assertSame([422, RecipeException::COMPONENT_DUPLICATE], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
        // l'inconnue (404) avant la profondeur (la tarte est utilisée : 422) et avant le secret (409)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => [['recipeId' => $secret->getId(), 'multiplier' => 1], ['recipeId' => $unknownId, 'multiplier' => 1]]]);
        $this->assertSame([404, RecipeException::notFound($unknownId)->getMessage()], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
        // la profondeur (422) avant le secret (409)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => [['recipeId' => $secret->getId(), 'multiplier' => 1], ['recipeId' => $dough->getId(), 'multiplier' => 1]]]);
        $this->assertSame([422, RecipeException::COMPONENT_CHAIN], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
    }

    public function testABodyWhoseComponentsAreNotAListOfRecipesIsRefusedBeforeAnythingElse(): void
    {
        // Given
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $bodies = [
            'un texte' => 'beaucoup',
            'un élément qui n\'est pas un objet' => [1],
            'sans identifiant' => [['multiplier' => 1]],
            'identifiant vide' => [['recipeId' => '', 'multiplier' => 1]],
            'identifiant trop long' => [['recipeId' => str_repeat('a', 37), 'multiplier' => 1]],
            'sans multiplicateur' => [['recipeId' => UuidGenerator::new()]],
            'multiplicateur nul' => [['recipeId' => UuidGenerator::new(), 'multiplier' => null]],
            'multiplicateur booléen' => [['recipeId' => UuidGenerator::new(), 'multiplier' => true]],
        ];

        // When & Then
        foreach ($bodies as $label => $components) {
            $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => $components]);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $label);
        }
        $this->assertSame([], $this->edges($pie->getId()));
    }

    // --- Les deux bascules du secret

    public function testARecipeThatAnOrdinaryRecipeUsesCannotBecomeSecretUntilItIsNoLongerUsed(): void
    {
        // Given : la tarte (ordinaire) utilise la pâte
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]], components: [[$dough, 1]]);

        // When : on rend la pâte secrète
        $refused = $this->answer('PUT', '/api/v1/recipes/' . $dough->getId(), $this->author, ['secret' => true] + $this->body('Pâte sablée', [[$this->flour, 250]]));

        // Then : `409`, rien n'est écrit
        $this->assertSame([409, ['error' => ['message' => RecipeException::SECRET_USED_BY_PUBLIC]]], [$refused['status'], $refused['json']]);
        $this->assertSame(0, $this->scalar('SELECT secret::int FROM recipe WHERE id = ?', $dough->getId()));

        // When : la tarte devient secrète d'abord, puis la pâte
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, ['secret' => true] + $this->body('Tarte', [[$this->sugar, 100]]));
        $pieStatus = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $dough->getId(), $this->author, ['secret' => true] + $this->body('Pâte sablée', [[$this->flour, 250]]));

        // Then : la tarte secrète peut utiliser une pâte secrète
        $this->assertSame([204, 204], [$pieStatus, $this->client->getResponse()->getStatusCode()]);
        $this->assertSame(1, $this->scalar('SELECT secret::int FROM recipe WHERE id = ?', $dough->getId()));
    }

    public function testARecipeThatUsesASecretOneCannotBecomeVisibleUntilItIsReplacedAndBothCanChangeTogether(): void
    {
        // Given : une tarte secrète utilise une pâte secrète
        $dough = $this->savedRecipe($this->author, 'Pâte secrète', [[$this->flour, 250]], secret: true);
        $plain = $this->savedRecipe($this->author, 'Pâte ordinaire', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte secrète', [[$this->sugar, 100]], secret: true, components: [[$dough, 1]]);

        // When : on la rend publique sans toucher à ses composants (`components` absent)
        $refused = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, ['secret' => false] + $this->body('Tarte secrète', [[$this->sugar, 100]]));

        // Then
        $this->assertSame([409, ['error' => ['message' => RecipeException::COMPONENT_SECRET]]], [$refused['status'], $refused['json']]);
        $this->assertSame(1, $this->scalar('SELECT secret::int FROM recipe WHERE id = ?', $pie->getId()));

        // When : avec une liste qui ne garde que du public, dans la MÊME requête (l'état final est jugé)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, ['secret' => false] + $this->body('Tarte', [[$this->sugar, 100]], [[$plain, 1]]));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([0, [$plain->getId()]], [
            $this->scalar('SELECT secret::int FROM recipe WHERE id = ?', $pie->getId()),
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT component FROM recipe_component WHERE recipe = ?', [$pie->getId()]),
        ]);

        // When : une recette publique veut la pâte secrète
        $secretUse = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[$dough, 1]]));

        // Then
        $this->assertSame([409, ['error' => ['message' => RecipeException::COMPONENT_SECRET]]], [$secretUse['status'], $secretUse['json']]);
    }

    // --- Recette sans propriétaire

    public function testARecipeWithoutAnOwnerNeverReceivesComponentsButEmptyOrAbsentIsHarmless(): void
    {
        // Given : la neutre d'une recette Marmiton (ses ingrédients sont ceux du propriétaire réservé) et une recette ordinaire de l'administrateur
        $neutral = new RecipeBuilder()->isPastaCarbonara()->asMarmitonNeutral('22082')->withAuthorId(Recipe::MARMITON_OWNER_ID)->build();
        foreach ($neutral->getRows() as $row) {
            $row->getIngredient()->setCreatorId(Recipe::MARMITON_OWNER_ID);
        }
        $this->persister->saveRecipe($neutral);
        $adminDough = $this->savedRecipe($this->admin, 'Pâte à moi', [[$this->ingredientOf($this->admin, 'Farine'), 250]]);
        $rows = array_map(
            static fn($row): array => ['ingredientSlug' => $row->getIngredient()->getSlug(), 'unitSlug' => $row->getUnit()->getSlug(), 'quantity' => $row->getQuantity()],
            array_values($neutral->getRows()->toArray()),
        );
        $body = ['name' => $neutral->getName(), 'serving' => 4, 'typeSlug' => $neutral->getType()->getSlug(), 'rows' => $rows];

        // When
        $refused = $this->answer('PUT', '/api/v1/recipes/' . $neutral->getId(), $this->admin, $body + ['components' => [['recipeId' => $adminDough->getId(), 'multiplier' => 1]]]);
        $this->request('PUT', '/api/v1/recipes/' . $neutral->getId(), $this->admin, $body + ['components' => []]);
        $empty = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $neutral->getId(), $this->admin, $body);
        $absent = $this->client->getResponse()->getStatusCode();

        // Then : `422 RECIPE_COMPONENT_OWNERLESS` ; `[]` et absent sont sans effet
        $this->assertSame([422, ['error' => ['message' => RecipeException::COMPONENT_OWNERLESS]]], [$refused['status'], $refused['json']]);
        $this->assertSame([204, 204], [$empty, $absent]);
        $this->assertSame([[], 0], [$this->edges($neutral->getId()), $this->scalar('SELECT component_count FROM recipe WHERE id = ?', $neutral->getId())]);
    }

    // --- L'administrateur : aucun passe-droit de plus

    public function testAnAdministratorWritesTheComponentsOfAnotherAuthorsOrdinaryRecipeWithThatAuthorsRecipesOnly(): void
    {
        // Given : la tarte ordinaire de l'auteur, sa pâte, la pâte de l'administrateur et la pâte secrète de l'auteur
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $secret = $this->savedRecipe($this->author, 'Crème secrète', [[$this->milk, 100]], secret: true);
        $adminDough = $this->savedRecipe($this->admin, 'Pâte à moi', [[$this->ingredientOf($this->admin, 'Farine'), 250]]);
        $unknownId = UuidGenerator::new();
        $body = $this->body('Tarte', [[$this->sugar, 100]]);

        // When : une recette de l'administrateur (pas de l'auteur de la tarte), puis la secrète de l'auteur, puis un identifiant inconnu
        $ofAdmin = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->admin, $body + ['components' => [['recipeId' => $adminDough->getId(), 'multiplier' => 1]]], $adminDough->getId());
        $ofSecret = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->admin, $body + ['components' => [['recipeId' => $secret->getId(), 'multiplier' => 1]]], $secret->getId());
        $unknown = $this->answer('PUT', '/api/v1/recipes/' . $pie->getId(), $this->admin, $body + ['components' => [['recipeId' => $unknownId, 'multiplier' => 1]]], $unknownId);

        // Then : `404` comme un identifiant inconnu, jamais un `409` qui révélerait la recette secrète de l'auteur
        $this->assertSame(404, $unknown['status']);
        $this->assertSame($unknown, $ofAdmin);
        $this->assertSame($unknown, $ofSecret);
        $this->assertStringNotContainsString('secrète', $ofSecret['body']);
        $this->assertSame([], $this->edges($pie->getId()));

        // When : une recette de l'AUTEUR de la tarte (le contrôle compare avec l'auteur, pas avec l'administrateur)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->admin, $body + ['components' => [['recipeId' => $dough->getId(), 'multiplier' => 2]]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[0, 2.0]], $this->edges($pie->getId()));
        // et il ne rend pas la recette secrète : ce choix est celui de l'auteur
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->admin, ['secret' => true] + $body);
        $this->assertSame([403, RecipeException::SECRET_FORBIDDEN], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message']]);
    }

    public function testAnAdministratorCannotReachTheSecretRecipeOfAnotherAuthorToGiveItComponents(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $secretPie = $this->savedRecipe($this->author, 'Tarte secrète', [[$this->sugar, 100]], secret: true);
        $unknownId = UuidGenerator::new();
        $body = $this->body('Tarte', [[$this->sugar, 100]]) + ['components' => [['recipeId' => $dough->getId(), 'multiplier' => 1]]];

        // When
        $onSecret = $this->answer('PUT', '/api/v1/recipes/' . $secretPie->getId(), $this->admin, $body, $secretPie->getId());
        $onUnknown = $this->answer('PUT', '/api/v1/recipes/' . $unknownId, $this->admin, $body, $unknownId);
        $usableOnSecret = $this->answer('GET', '/api/v1/recipes/usable?forRecipeId=' . $secretPie->getId(), $this->admin, null, $secretPie->getId());
        $usableOnUnknown = $this->answer('GET', '/api/v1/recipes/usable?forRecipeId=' . $unknownId, $this->admin, null, $unknownId);
        $usagesOnSecret = $this->answer('GET', '/api/v1/recipes/' . $secretPie->getId() . '/used-in', $this->admin, null, $secretPie->getId());
        $usagesOnUnknown = $this->answer('GET', '/api/v1/recipes/' . $unknownId . '/used-in', $this->admin, null, $unknownId);

        // Then : `404`, la même réponse que pour une recette qui n'existe pas
        $this->assertSame(404, $onUnknown['status']);
        $this->assertSame($onUnknown, $onSecret);
        $this->assertSame($usableOnUnknown, $usableOnSecret);
        $this->assertSame($usagesOnUnknown, $usagesOnSecret);
        $this->assertSame(404, $usableOnSecret['status']);
        $this->assertSame(404, $usagesOnSecret['status']);
        $this->assertSame([], $this->edges($secretPie->getId()));
    }

    // --- Supprimer

    public function testARecipeThatAnotherUsesCannotBeRemovedUntilItIsRemovedFromIt(): void
    {
        // Given
        [$dough, $cream, $pie] = $this->pieWithTwoUsedRecipes();

        // When : on supprime la pâte, utilisée
        $refused = $this->answer('DELETE', '/api/v1/recipes/' . $dough->getId(), $this->author);

        // Then : `409 RECIPE_USED_AS_COMPONENT`, la pâte et l'arête sont toujours là
        $this->assertSame([409, ['error' => ['message' => RecipeException::USED_AS_COMPONENT]]], [$refused['status'], $refused['json']]);
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM recipe WHERE id = ?', $dough->getId()));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe_component WHERE recipe = ?', $pie->getId()));

        // When : la tarte n'utilise plus la pâte (elle garde la crème), puis on supprime la pâte
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, $this->body('Tarte', [[$this->sugar, 100]], [[$cream, 0.5]]));
        $this->request('DELETE', '/api/v1/recipes/' . $dough->getId(), $this->author);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM recipe WHERE id = ?', $dough->getId()));
    }

    public function testRemovingAComposedRecipeRemovesItsEdgesAndLeavesTheRecipesItUsedIntact(): void
    {
        // Given
        [$dough, $cream, $pie] = $this->pieWithTwoUsedRecipes();

        // When
        $this->request('DELETE', '/api/v1/recipes/' . $pie->getId(), $this->author);

        // Then : la tarte et ses arêtes ont disparu, la pâte et la crème restent, et elles peuvent maintenant être supprimées
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([0, 0], [$this->scalar('SELECT COUNT(*) FROM recipe WHERE id = ?', $pie->getId()), $this->scalar('SELECT COUNT(*) FROM recipe_component WHERE recipe = ?', $pie->getId())]);
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe WHERE id IN (?, ?)', $dough->getId(), $cream->getId()));
        $this->request('DELETE', '/api/v1/recipes/' . $dough->getId(), $this->author);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    // --- Les recettes proposables et les recettes qui en utilisent une

    public function testTheUsableRecipesAreSortedByNameSearchedAndExcludeWhatCannotBeUsed(): void
    {
        // Given : l'auteur a trois recettes simples, une composée, la tarte qu'il écrit, une secrète ; un autre compte a la sienne
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]], serving: 6);
        $cream = $this->savedRecipe($this->author, 'Crème', [[$this->milk, 200]], serving: 2);
        $this->savedRecipe($this->author, 'Compote', [[$this->sugar, 50]], recipeType: 'plat');
        $menu = $this->savedRecipe($this->author, 'Menu', [[$this->milk, 100]], components: [[$dough, 1]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $secret = $this->savedRecipe($this->author, 'Chantilly secrète', [[$this->milk, 100]], secret: true);
        $this->savedRecipe($this->other, 'Pâte du voisin', [[$this->ingredientOf($this->other, 'Farine'), 250]]);

        // When : en créant (aucune recette écrite encore)
        $this->request('GET', '/api/v1/recipes/usable', $this->author);
        $creating = $this->jsonBody();
        // When : en modifiant la tarte
        $this->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $pie->getId(), $this->author);
        $editing = $this->jsonBody();

        // Then : triées par nom, ni la composée (Menu), ni une recette d'un autre, ni la secrète tant que la recette écrite n'est pas secrète ; en créant la tarte (qui n'existe pas encore)
        // elle y est, en la modifiant elle n'y est plus
        $this->assertSame(['Compote', 'Crème', 'Pâte sablée', 'Tarte'], array_column($creating['member'], 'name'));
        $this->assertSame(['Compote', 'Crème', 'Pâte sablée'], array_column($editing['member'], 'name'));
        $this->assertSame([4, true], [$creating['totalItems'], $creating['canHaveComponents']]);
        $this->assertSame([3, true], [$editing['totalItems'], $editing['canHaveComponents']]);
        // chaque recette dit ce qu'il faut pour l'afficher
        $this->assertSame(
            ['id' => $dough->getId(), 'name' => 'Pâte sablée', 'serving' => 6, 'typeSlug' => 'dessert', 'typeName' => 'dessert', 'secret' => false],
            $editing['member'][2],
        );
        $this->assertSame('plat', $editing['member'][0]['typeSlug']);

        // When : la recette écrite est (ou va devenir) secrète
        $this->request('GET', '/api/v1/recipes/usable?secret=true&forRecipeId=' . $pie->getId(), $this->author);
        $secretly = $this->jsonBody();

        // Then : les recettes secrètes de l'auteur sont proposées aussi
        $this->assertSame(['Chantilly secrète', 'Compote', 'Crème', 'Pâte sablée'], array_column($secretly['member'], 'name'));
        $this->assertSame([$secret->getId(), true], [$secretly['member'][0]['id'], $secretly['member'][0]['secret']]);

        // When : une recherche tolère la faute de frappe et classe par pertinence
        $this->request('GET', '/api/v1/recipes/usable?q=' . rawurlencode('pate sable'), $this->author);
        $searched = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/usable?q=' . rawurlencode('!!!'), $this->author);
        $nothing = $this->jsonBody();

        // Then
        $this->assertSame([$dough->getId()], array_column($searched['member'], 'id'));
        $this->assertSame([[], 0, true], [$nothing['member'], $nothing['totalItems'], $nothing['canHaveComponents']]);
        $this->assertNotContains($menu->getId(), array_column($creating['member'], 'id'));
    }

    public function testTheSecretRecipesOfTheAuthorAreNeverProposedToAnAdministratorWhoEditsHisRecipe(): void
    {
        // Given : la tarte ordinaire de l'auteur, sa pâte ordinaire et sa crème secrète
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $this->savedRecipe($this->author, 'Crème secrète', [[$this->milk, 100]], secret: true);

        // When : l'administrateur, même avec `secret=true`
        $this->request('GET', '/api/v1/recipes/usable?secret=true&forRecipeId=' . $pie->getId(), $this->admin);
        $asAdmin = $this->jsonBody();
        $status = $this->client->getResponse()->getStatusCode();

        // Then : les recettes de l'AUTEUR de la tarte, ordinaires seulement
        $this->assertSame([200, [$dough->getId()]], [$status, array_column($asAdmin['member'], 'id')]);
    }

    public function testARecipeThatIsUsedCannotHaveComponentsAndSaysSoToTheEditor(): void
    {
        // Given : la pâte est utilisée par la tarte
        [$dough, , $pie] = $this->pieWithTwoUsedRecipes();

        // When
        $this->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $dough->getId(), $this->author);
        $forDough = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $pie->getId(), $this->author);
        $forPie = $this->jsonBody();

        // Then : la pâte ne peut pas avoir de recette utilisée (le front masque la section), la tarte oui
        $this->assertFalse($forDough['canHaveComponents']);
        $this->assertTrue($forPie['canHaveComponents']);
        $this->assertSame(['id', 'name', 'serving', 'typeSlug', 'typeName', 'secret'], array_keys($forPie['member'][0]));
    }

    public function testTheUsableRecipesOfARecipeOfAnotherAreRefusedToAnOrdinaryUser(): void
    {
        // Given
        $theirs = $this->savedRecipe($this->other, 'Tarte du voisin', [[$this->ingredientOf($this->other, 'Farine'), 250]]);
        $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);

        // When
        $this->request('GET', '/api/v1/recipes/usable?forRecipeId=' . $theirs->getId(), $this->author);

        // Then : comme `PUT` sur cette recette, `403` (elle existe, elle n'est pas à lui)
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheUsableRecipesAreCappedAtTwoHundredAndTotalItemsGivesTheRealNumber(): void
    {
        // Given : 205 recettes simples
        for ($number = 1; $number <= 205; $number++) {
            $this->savedRecipe($this->author, sprintf('Recette %03d', $number), [[$this->sugar, 10]]);
        }

        // When
        $this->request('GET', '/api/v1/recipes/usable', $this->author);
        $all = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/usable?q=' . rawurlencode('Recette'), $this->author);
        $searched = $this->jsonBody();

        // Then : 200 au plus, le vrai nombre dans `totalItems`, sans recherche comme avec
        $this->assertSame([200, 205, 'Recette 001'], [count($all['member']), $all['totalItems'], $all['member'][0]['name']]);
        $this->assertSame([200, 205], [count($searched['member']), $searched['totalItems']]);
    }

    public function testTheRecipesThatUseARecipeAreListedByNameForItsOwnerOnly(): void
    {
        // Given : la pâte est utilisée par une tarte ordinaire et par une quiche secrète ; la crème par personne
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]], components: [[$dough, 1]]);
        $quiche = $this->savedRecipe($this->author, 'Quiche', [[$this->milk, 100]], secret: true, components: [[$dough, 1]]);
        $cream = $this->savedRecipe($this->author, 'Crème', [[$this->milk, 200]]);

        // When
        $this->request('GET', '/api/v1/recipes/' . $dough->getId() . '/used-in', $this->author);
        $own = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/' . $cream->getId() . '/used-in', $this->author);
        $unused = $this->jsonBody();
        // L'administrateur lit les usages d'une recette ordinaire d'un autre : la quiche secrète de l'auteur ne lui est pas montrée
        $this->request('GET', '/api/v1/recipes/' . $dough->getId() . '/used-in', $this->admin);
        $asAdmin = $this->jsonBody();
        // Un autre utilisateur n'a pas le droit
        $this->request('GET', '/api/v1/recipes/' . $dough->getId() . '/used-in', $this->other);
        $otherStatus = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame(
            ['member' => [['id' => $quiche->getId(), 'name' => 'Quiche', 'secret' => true], ['id' => $pie->getId(), 'name' => 'Tarte', 'secret' => false]], 'totalItems' => 2],
            ['member' => array_map(static fn(array $recipe): array => array_slice($recipe, 0, 3), $own['member']), 'totalItems' => $own['totalItems']],
        );
        $this->assertSame(['member' => [], 'totalItems' => 0], $unused);
        $this->assertSame([[$pie->getId()], 1], [array_column($asAdmin['member'], 'id'), $asAdmin['totalItems']]);
        $this->assertSame(403, $otherStatus);
    }

    // --- Les autres cas d'usage qui enregistrent une recette ne perdent rien

    public function testAnotherUserCannotWriteTheComponentsOfARecipeThatIsNotHis(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]]);
        $pie = $this->savedRecipe($this->author, 'Tarte', [[$this->sugar, 100]]);

        // When : un autre compte modifie la tarte de l'auteur
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->other, $this->body('Tarte', [[$this->ingredientOf($this->other, 'Farine'), 100]], [[$dough, 1]]));

        // Then : `403` (le droit sur la recette), rien n'est écrit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->edges($pie->getId()));
    }

    // --- Aides

    /**
     * La tarte (4 personnes, 100 g de sucre) qui utilise la pâte (×2) puis la crème (×0,5).
     *
     * @return array{Recipe, Recipe, Recipe} la pâte, la crème, la tarte
     */
    private function pieWithTwoUsedRecipes(): array
    {
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250]], serving: 6);
        $cream = $this->savedRecipe($this->author, 'Crème', [[$this->milk, 200]], serving: 2);
        $pie = $this->savedRecipe($this->author, 'Tarte au citron', [[$this->sugar, 100]], components: [[$dough, 2], [$cream, 0.5]]);

        return [$dough, $cream, $pie];
    }

    /**
     * Un corps de requête de recette.
     *
     * @param list<array{Ingredient, int}> $rows
     * @param list<array{Recipe|array{id: string}, float|int}>|null $components `null` : la clé `components` est absente du corps (un ancien client)
     * @return array<string, mixed>
     */
    private function body(string $name, array $rows, ?array $components = null): array
    {
        $body = [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'dessert',
            'rows' => array_map(static fn(array $row): array => ['ingredientSlug' => $row[0]->getSlug(), 'unitSlug' => 'gramme', 'quantity' => $row[1]], $rows),
        ];
        if ($components !== null) {
            $body['components'] = array_map(static fn(array $component): array => ['recipeId' => $component[0] instanceof Recipe ? $component[0]->getId() : $component[0]['id'], 'multiplier' => $component[1]], $components);
        }

        return $body;
    }

    /**
     * @param list<array{Ingredient, int}> $rows les lignes, en grammes
     * @param list<array{Recipe, float}> $components les recettes utilisées et leur multiplicateur
     */
    private function savedRecipe(User $author, string $name, array $rows, int $serving = 4, bool $secret = false, array $components = [], string $recipeType = 'dessert'): Recipe
    {
        $builder = new RecipeBuilder()->withAuthor($author)->withName($name)->withServing($serving)->asSecret($secret)
            ->withRecipeType($recipeType === 'dessert' ? new RecipeTypeBuilder()->isDessert() : new RecipeTypeBuilder()->isMeal());
        foreach ($rows as [$ingredient, $quantity]) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity($quantity));
        }
        foreach ($components as [$used, $multiplier]) {
            $builder->withComponent($used, $multiplier);
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * Un ingrédient du propriétaire, acheté au kilo et cuisiné au gramme (le kilo se convertit en gramme).
     */
    private function ingredientOf(User $owner, string $name): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withCreator($owner, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function createdList(User $user): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $user);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return basename((string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Les lignes d'une liste : `slug => quantité` (dans l'unité d'achat).
     *
     * @return array<string, float>
     */
    private function listRows(string $listId): array
    {
        $this->entityManager->clear();

        return array_map('floatval', $this->entityManager->getConnection()->fetchAllKeyValue('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]));
    }

    /**
     * Les arêtes d'une recette, dans l'ordre : position et multiplicateur.
     *
     * @return list<array{int, float}>
     */
    private function edges(string $recipeId): array
    {
        return array_map(
            static fn(array $edge): array => [(int) $edge['position'], (float) $edge['multiplier']],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT position, multiplier FROM recipe_component WHERE recipe = ? ORDER BY position', [$recipeId]),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function edgesWithIds(string $recipeId): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, recipe, component, multiplier, position FROM recipe_component WHERE recipe = ? ORDER BY position', [$recipeId]);
    }

    /**
     * L'état d'une recette tel qu'un refus doit le laisser : sa ligne, ses lignes et ses arêtes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $recipeId): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'recipe' => $connection->fetchAssociative('SELECT name, serving, version, secret, component_count FROM recipe WHERE id = ?', [$recipeId]),
            'rows' => $connection->fetchAllAssociative('SELECT ingredient, quantity FROM recipe_row WHERE recipe = ? ORDER BY position', [$recipeId]),
            'edges' => $this->edgesWithIds($recipeId),
        ];
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder()->withServing(4);
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
     * La réponse à une requête, comparable : le statut, le corps décodé, le corps brut (l'identifiant `$id` remplacé) et les en-têtes sans la date.
     *
     * @param array<string, mixed>|null $body
     * @return array{status: int, json: mixed, body: string, headers: array<string, list<string|null>>}
     */
    private function answer(string $method, string $uri, User $user, ?array $body = null, ?string $id = null): array
    {
        $this->request($method, $uri, $user, $body);
        $response = $this->client->getResponse();
        $headers = $response->headers->all();
        unset($headers['date']);
        $raw = (string) $response->getContent();
        $normalized = $id === null ? $raw : str_replace($id, '{id}', $raw);

        return [
            'status' => $response->getStatusCode(),
            'json' => $normalized === '' ? null : json_decode($normalized, true),
            'body' => $normalized,
            'headers' => $headers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}

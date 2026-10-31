<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\UuidGenerator;
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

        // Then : tout passe, elle est toujours secrète, et sa copie l'est aussi (copier ne rend pas publique une recette cachée)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->recipes()->findOneById($secret->getId())->isSecret());
        $this->assertTrue($this->recipes()->findOneById($copyId)->isSecret());
        // Aucun autre ne la trouve : ni la copie ni l'original ne sont dans les recettes des autres
        $this->request('GET', '/api/v1/recipes/other-people', $this->other);
        $this->assertSame([[], 0], [$this->jsonBody()['member'], $this->jsonBody()['totalItems']]);
        $this->request('GET', '/api/v1/recipes/' . $copyId, $this->other);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
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

    public function testTheCopyOfAnOrdinaryRecipeIsOrdinaryAndTheChoiceOfItsCopierIsFree(): void
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

    public function testAnAdministratorHasNoPassThroughOnTheSecretRecipeOfAnotherAuthorToEditOrDeleteIt(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);

        // When : il la remplace, la supprime, la rend publique
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->admin, $this->body('Tarte piratée'));
        $replaces = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $secret->getId(), $this->admin, ['secret' => false] + $this->body('Tarte publiée'));
        $publishes = $this->client->getResponse()->getStatusCode();
        $this->request('DELETE', '/api/v1/recipes/' . $secret->getId(), $this->admin);
        $deletes = $this->client->getResponse()->getStatusCode();

        // Then : elle n'existe pas pour lui, et rien n'a bougé
        $this->assertSame([404, 404, 404], [$replaces, $publishes, $deletes]);
        $stored = $this->recipes()->findOneById($secret->getId());
        $this->assertSame(['Tarte secrète', true, 1], [$stored->getName(), $stored->isSecret(), $stored->getVersion()]);
    }

    public function testAnAdministratorKeepsEditingAndDeletingOrdinaryRecipesButCannotHideThemNorChangeTheChoice(): void
    {
        // Given : le passe-droit de l'administrateur sur les recettes ordinaires est ce qu'il était
        $public = $this->savedRecipe($this->author, 'Tarte ordinaire');
        $other = $this->savedRecipe($this->author, 'Tarte à supprimer');

        // When : une modification ordinaire, avec ou sans le choix actuel
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->admin, $this->body('Tarte ordinaire corrigée'));
        $omitted = $this->client->getResponse()->getStatusCode();
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->admin, ['secret' => false] + $this->body('Tarte ordinaire revue'));
        $sameValue = $this->client->getResponse()->getStatusCode();
        // Mais il ne cache pas la recette d'un autre
        $this->request('PUT', '/api/v1/recipes/' . $public->getId(), $this->admin, ['secret' => true] + $this->body('Tarte ordinaire cachée'));
        $hides = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $this->request('DELETE', '/api/v1/recipes/' . $other->getId(), $this->admin);
        $deletes = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([204, 204, 204], [$omitted, $sameValue, $deletes]);
        $this->assertSame([403, ['error' => ['message' => RecipeException::SECRET_FORBIDDEN]]], $hides);
        $stored = $this->recipes()->findOneById($public->getId());
        $this->assertSame(['Tarte ordinaire revue', false], [$stored->getName(), $stored->isSecret()]);
    }

    public function testAnAdministratorEditsAndDeletesHisOwnSecretRecipe(): void
    {
        // Given : l'administrateur est l'auteur
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($flour);
        $mine = new RecipeBuilder()->withAuthor($this->admin)->withName('Ma tarte secrète')->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))->asSecret()->build();
        $this->persister->saveRecipe($mine);
        $body = ['rows' => [['ingredientSlug' => $flour->getSlug(), 'unitSlug' => 'gramme', 'quantity' => 250]]] + $this->body('Ma tarte revue');

        // When & Then : comme n'importe quel auteur
        $this->request('GET', '/api/v1/recipes/' . $mine->getId(), $this->admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/recipes/' . $mine->getId(), $this->admin, ['secret' => false] + $body);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->recipes()->findOneById($mine->getId())->isSecret());
        $this->request('DELETE', '/api/v1/recipes/' . $mine->getId(), $this->admin);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Pour chaque façon d'atteindre une recette, le lecteur qui n'en est pas l'auteur (et l'administrateur) reçoit de la recette secrète EXACTEMENT
     * ce qu'il reçoit d'un identifiant de recette qui n'existe pas : le statut, le corps (au nom de la recette près) et les en-têtes.
     */
    public function testASecretRecipeIsIndistinguishableFromAnUnknownOneInEveryWayToReachIt(): void
    {
        // Given
        $secret = $this->savedRecipe($this->author, 'Tarte secrète', secret: true);
        $unknownId = UuidGenerator::new();
        $ways = [
            'lire' => ['GET', '/api/v1/recipes/%s', null],
            'copier' => ['POST', '/api/v1/recipes/%s/copy', null],
            'remplacer' => ['PUT', '/api/v1/recipes/%s', $this->body('Pirate')],
            'rendre publique' => ['PUT', '/api/v1/recipes/%s', ['secret' => false] + $this->body('Pirate')],
            'supprimer' => ['DELETE', '/api/v1/recipes/%s', null],
            'planifier' => ['PUT', '/api/v1/shopping-lists/active/recipes/%s', null],
            'retirer du plan' => ['DELETE', '/api/v1/shopping-lists/active/recipes/%s', null],
        ];

        foreach (['un autre utilisateur' => $this->other, 'l\'administrateur' => $this->admin] as $who => $reader) {
            $this->savedActiveList($reader);
            foreach ($ways as $way => [$method, $pattern, $body]) {
                // When
                $onSecret = $this->answer($method, sprintf($pattern, $secret->getId()), $reader, $body, $secret->getId());
                $onUnknown = $this->answer($method, sprintf($pattern, $unknownId), $reader, $body, $unknownId);

                // Then : un 404, et la même réponse
                $this->assertSame(404, $onUnknown['status'], "$who, $way : un identifiant inconnu doit répondre 404");
                $this->assertSame($onUnknown, $onSecret, "$who, $way : la recette secrète se distingue d'une recette inconnue");
                $this->assertStringNotContainsString('secrète', $onSecret['body']);
            }
        }
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM meal'));
        $this->assertTrue($this->recipes()->findOneById($secret->getId())->isSecret());
    }

    public function testAMealInheritedFromTheSecretRecipeOfAnotherShowsNoNameCannotBeEditedNorRemovedToReadItsContent(): void
    {
        // Given : la liste de «other» a de la farine, un repas hérité (d'avant la garde de `addMeal`) vers la recette ORDINAIRE d'un auteur, et un
        // repas orphelin (recette retirée)
        $public = $this->savedRecipe($this->author, 'Tarte de mamie');
        $listId = $this->savedActiveList($this->other, withFlour: true);
        $mealId = UuidGenerator::new();
        $orphanMealId = UuidGenerator::new();
        $orphanRecipeId = UuidGenerator::new();
        $this->entityManager->getConnection()->executeStatement('INSERT INTO meal (id, shopping_list, recipe, serving) VALUES (?, ?, ?, 4), (?, ?, ?, 4)', [$mealId, $listId, $public->getId(), $orphanMealId, $listId, $orphanRecipeId]);
        $this->request('GET', '/api/v1/shopping-lists/' . $listId, $this->other);
        $before = $this->jsonBody();

        // When : l'auteur la rend secrète
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET secret = TRUE WHERE id = ?', [$public->getId()]);
        $this->request('GET', '/api/v1/shopping-lists/' . $listId, $this->other);
        $content = (string) $this->client->getResponse()->getContent();
        $after = $this->jsonBody();

        // Then : elle se lisait, plus maintenant ; ni son nom ni son identifiant ne sortent de la liste de «other», ses quantités sont les siennes
        $this->assertSame(['Tarte de mamie'], array_column($before['recipeTypes'][0]['meals'], 'recipeName'));
        $this->assertSame([[], 0], [$after['recipeTypes'], $after['recipeCount']]);
        $this->assertStringNotContainsString('Tarte de mamie', $content);
        $this->assertStringNotContainsString($public->getId(), $content);
        $rowsBefore = $this->listRows($listId);

        // When : il change le nombre de personnes du repas hérité (une autre valeur, puis la même) ou retire la recette du plan
        $this->request('PUT', '/api/v1/meals/' . $mealId . '/serving', $this->other, ['serving' => 6]);
        $changes = $this->answerOfLast($public->getId());
        $this->request('PUT', '/api/v1/meals/' . $mealId . '/serving', $this->other, ['serving' => 4]);
        $same = $this->answerOfLast($public->getId());
        $this->request('PUT', '/api/v1/meals/' . $orphanMealId . '/serving', $this->other, ['serving' => 6]);
        $orphan = $this->answerOfLast($orphanRecipeId);
        $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $public->getId(), $this->other);
        $removes = $this->answerOfLast($public->getId());

        // Then : 404 comme pour une recette retirée (même corps, mêmes en-têtes), et la liste n'a pas bougé : aucune quantité ne dit le contenu
        $this->assertSame(404, $orphan['status']);
        $this->assertSame([$orphan, $orphan], [$changes, $same]);
        $this->assertSame(404, $removes['status']);
        $this->assertSame($rowsBefore, $this->listRows($listId));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM meal WHERE shopping_list = ?', $listId));
        $this->assertSame(4, $this->scalar('SELECT serving FROM meal WHERE id = ?', $mealId));
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

    private function savedActiveList(User $owner, bool $withFlour = false): string
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        $list = ($withFlour ? $builder->addIngredient($this->flour) : $builder)->build();
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

    /**
     * La réponse à une requête, comparable : le statut, le corps (l'identifiant de la recette remplacé) et les en-têtes sans la date.
     *
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string, headers: array<string, list<string|null>>}
     */
    private function answer(string $method, string $uri, User $user, ?array $body, string $id): array
    {
        $this->request($method, $uri, $user, $body);

        return $this->answerOfLast($id);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, list<string|null>>}
     */
    private function answerOfLast(string $id): array
    {
        $response = $this->client->getResponse();
        $headers = $response->headers->all();
        unset($headers['date']);

        return [
            'status' => $response->getStatusCode(),
            'body' => str_replace($id, '{id}', (string) $response->getContent()),
            'headers' => $headers,
        ];
    }

    /**
     * @return list<array{string, string}> les lignes de la liste : ingrédient et quantité
     */
    private function listRows(string $listId): array
    {
        return array_map(
            static fn(array $row): array => [$row['ingredient'], (string) $row['quantity']],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$listId]),
        );
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

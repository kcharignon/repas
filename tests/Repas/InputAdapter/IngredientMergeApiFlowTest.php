<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
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
 * Fusionner deux ingrédients de bout en bout (ADR 0028 décision 9, ING-005), avec les vrais bus, la vraie base, les listeners et le JWT : un
 * utilisateur crée « Tomate » et « Tomates », des recettes (dont une qui contient les deux), les planifie, ajoute « Tomates » à la main à sa
 * liste, puis fusionne. Il relit ensuite ses recettes et sa liste, retire un repas, envoie un ancien slug, rejoue la fusion, et un client qui avait
 * lu l'ancienne version reçoit 412.
 *
 * Les rayons et les unités sont les leurs : boulangerie, achat au kilo, comptage au gramme (conversion commune).
 */
class IngredientMergeApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
    }

    public function testTheMergeMovesRecipesListsAndOldSlugsAndStopsAnOutdatedClient(): void
    {
        // Given : « Tomate » (A, gardé) et « Tomates » (B, absorbé) ; trois recettes : R1 a les deux, R2 n'a que B, R3 n'a que A
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $this->assertSame(2, $this->createdIngredients($user));
        $r1 = $this->createRecipe($user, 'Salade', [[$a, 200], [$b, 300]]);
        $r2 = $this->createRecipe($user, 'Sauce', [[$b, 500]]);
        $r3 = $this->createRecipe($user, 'Soupe', [[$a, 400]]);
        // une recette d'un autre utilisateur, qui a ses propres « Tomate » et « Tomates » : jamais touchée
        $other = $this->savedUser();
        $otherB = $this->createIngredient($other, 'Tomates');
        $this->createRecipe($other, 'Autre', [[$otherB, 100]]);
        $otherBefore = $this->scalar("SELECT COUNT(*) FROM recipe_row WHERE ingredient = '$otherB'");

        // et sa liste active : R1 et R2 planifiées, plus deux ajouts à la main de B
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$r1", $user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$r2", $user);
        $this->request('POST', "/api/v1/shopping-lists/active/ingredients/$b/increase", $user);
        $this->request('POST', "/api/v1/shopping-lists/active/ingredients/$b/increase", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        // R1 : 0,2 kg de A et 0,3 kg de B ; R2 : 0,5 kg de B ; chaque ajout à la main pose aussi un kilo sur la ligne de B
        $this->assertEqualsWithDelta([$a => 0.2, $b => 2.8], $this->listRows($listId), 1e-9);
        $this->assertSame([[$b, 2.0, 'kilo']], $this->listItems($listId));

        // un client a lu la recette R2 et la liste avant la fusion
        $this->request('GET', "/api/v1/recipes/$r2", $user);
        $staleRecipeEtag = $this->client->getResponse()->headers->get('ETag');
        $this->request('GET', "/api/v1/shopping-lists/$listId", $user);
        $staleListEtag = $this->client->getResponse()->headers->get('ETag');
        $this->assertNotNull($staleRecipeEtag);
        $this->assertNotNull($staleListEtag);

        // L'aperçu dit ce qui va se passer
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $preview = $this->jsonBody();
        $this->assertSame([2, 1, 0, false, false, [], null, true], [$preview['recipeCount'], $preview['listCount'], $preview['driveChoiceCount'], $preview['departmentChanges'], $preview['listQuantitiesConverted'], $preview['missingUnits'], $preview['blockedBy'], $preview['canMerge']]);
        $this->assertSame(['Tomate', 'Tomates'], [$preview['keep']['name'], $preview['absorbed']['name']]);
        $this->assertSame(2, $this->scalar("SELECT COUNT(*) FROM ingredient WHERE creator = '{$user->getId()}'"), 'L\'aperçu n\'écrit rien');

        // When : la fusion
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : 204, « Tomates » n'existe plus, plus aucune ligne dessus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM ingredient WHERE slug = '$b'"));
        foreach (['recipe_row', 'shopping_list_row', 'shopping_list_ingredient'] as $table) {
            $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM $table WHERE ingredient = '$b'"), $table);
        }
        // les recettes : R1 garde ses deux lignes (A, A) avec leurs quantités, R2 passe sur A, R3 n'a pas bougé
        $this->assertSame([[$a, 200.0, 'gramme'], [$a, 300.0, 'gramme']], $this->recipeRows($r1));
        $this->assertSame([[$a, 500.0, 'gramme']], $this->recipeRows($r2));
        $this->assertSame([[$a, 400.0, 'gramme']], $this->recipeRows($r3));
        $this->assertSame($otherBefore, $this->scalar("SELECT COUNT(*) FROM recipe_row WHERE ingredient = '$otherB'"));
        // la liste : une seule ligne A, la somme (0,2 + 2,8 kg), et le compteur à la main est passé sur A
        $this->assertEqualsWithDelta([$a => 3.0], $this->listRows($listId), 1e-9);
        $this->assertSame([[$a, 2.0, 'kilo']], $this->listItems($listId));
        // la statistique « ingrédients créés » est descendue d'un cran
        $this->assertSame(1, $this->createdIngredients($user));

        // un client qui avait lu l'ancienne version de la recette ou de la liste reçoit 412, pas une ligne sur un ingrédient supprimé
        $this->request('PUT', "/api/v1/recipes/$r2", $user, ['name' => 'Sauce', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => $b, 'unitSlug' => 'gramme', 'quantity' => 500]]], ['HTTP_IF_MATCH' => $staleRecipeEtag]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->request('PATCH', "/api/v1/shopping-lists/$listId", $user, ['name' => 'Ma liste'], ['HTTP_IF_MATCH' => $staleListEtag]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM recipe_row WHERE ingredient = '$b'"));

        // un client qui envoie encore l'ancien slug (onglet ouvert) atterrit sur A, sans If-Match : la recette R2 se met à jour avec A
        $this->request('PUT', "/api/v1/recipes/$r2", $user, ['name' => 'Sauce', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => $b, 'unitSlug' => 'gramme', 'quantity' => 600]]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[$a, 600.0, 'gramme']], $this->recipeRows($r2));
        $this->request('POST', "/api/v1/shopping-lists/active/ingredients/$b/increase", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[$a, 3.0, 'kilo']], $this->listItems($listId));
        $this->assertEqualsWithDelta([$a => 4.0], $this->listRows($listId), 1e-9);
        $this->request('POST', "/api/v1/shopping-lists/active/ingredients/$b/decrease", $user);
        $this->assertSame([[$a, 2.0, 'kilo']], $this->listItems($listId));
        $this->assertEqualsWithDelta([$a => 3.0], $this->listRows($listId), 1e-9);
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM shopping_list_ingredient WHERE ingredient = '$b'"));

        // retirer le repas R1 du plan retrouve la ligne de A et en retire les quantités des DEUX lignes de la recette (0,2 + 0,3 kg)
        $this->request('DELETE', "/api/v1/shopping-lists/active/recipes/$r1", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$a => 2.5], $this->listRows($listId), 1e-9);
        // et le replanifier, avec ses deux lignes devenues deux lignes de « Tomate », y remet les mêmes quantités
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$r1", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$a => 3.0], $this->listRows($listId), 1e-9);

        // rejouée, la fusion répond encore 204 et ne change rien
        $hash = $this->tablesHash();
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($hash, $this->tablesHash());
        // vers un autre ingrédient que celui de l'alias : inconnu
        $c = $this->createIngredient($user, 'Concombre');
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $c]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testARequestThatLoadedTheRecipeOrTheListBeforeTheMergeCannotRecreateALineOnTheDeletedIngredient(): void
    {
        // Given : une requête qui a chargé la recette R et la liste L (leurs entités gardent la version lue), pendant qu'une fusion les modifie
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $recipeId = $this->createRecipe($user, 'Sauce', [[$b, 500]]);
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipeId", $user);
        $this->entityManager->clear();
        // (le même gestionnaire d'entités garde les versions lues : la fusion passe par ses requêtes SQL en masse, qui ne les mettent pas à jour)
        $recipes = static::getContainer()->get(RecipeRepository::class);
        $lists = static::getContainer()->get(ShoppingListRepository::class);
        $recipe = $recipes->findOneById($recipeId);
        $list = $lists->findOneById($listId);

        // When : la fusion passe entre la lecture et l'écriture
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($user->getId(), $b, $a));

        // Then : enregistrer l'état lu avant la fusion est un conflit (409), pas une ligne qui retombe sur l'ingrédient supprimé
        $this->assertSame([$b], array_map(static fn($row): string => $row->getIngredient()->getSlug(), $recipe->getRows()->toArray()));
        foreach ([[static fn() => $recipes->save($recipe), RecipeException::class], [static fn() => $lists->save($list), ShoppingListException::class]] as [$save, $exception]) {
            try {
                $save();
                $this->fail('un enregistrement périmé ne doit pas passer');
            } catch (DomainException $failure) {
                $this->assertInstanceOf($exception, $failure);
                $this->assertSame(409, $failure->getCode());
            }
        }
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM recipe_row WHERE ingredient = '$b'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM shopping_list_row WHERE ingredient = '$b'"));
    }

    public function testTheMergeRefusalsAreAnsweredWithTheirCodesAndWriteNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $admin = $this->savedUser(true);
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $theirs = $this->createIngredient($other, 'Tomate');
        // L'ingrédient du propriétaire du catalogue : à un autre compte, pour tous les autres
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $catalogOwners = 'farine';
        $this->createRecipe($user, 'Sauce', [[$b, 500, 'gramme']]);
        $hash = $this->tablesHash();

        /** @var array<string, array{User, string, array<string, string>, int, ?string}> $cases */
        $cases = [
            'le même ingrédient' => [$user, $b, ['into' => $b], 422, 'CANNOT_MERGE_INTO_ITSELF'],
            'l\'ingrédient d\'un autre absorbé' => [$user, $theirs, ['into' => $a], 404, null],
            'l\'ingrédient d\'un autre gardé' => [$user, $b, ['into' => $theirs], 404, null],
            'un ingrédient inconnu' => [$user, 'inconnu', ['into' => $a], 404, null],
            'un administrateur n\'a pas de passe-droit (ingrédient d\'un autre)' => [$admin, $b, ['into' => $a], 404, null],
            'un administrateur ne fusionne pas l\'ingrédient du propriétaire du catalogue' => [$admin, $catalogOwners, ['into' => $a], 404, null],
            'sans cible' => [$user, $b, [], 422, null],
        ];
        foreach ($cases as $label => [$caller, $slug, $body, $status, $message]) {
            // When
            $this->request('POST', "/api/v1/ingredients/$slug/merge", $caller, $body);

            // Then
            $this->assertSame($status, $this->client->getResponse()->getStatusCode(), $label);
            if ($message !== null) {
                $this->assertSame(['error' => ['message' => $message]], $this->jsonBody(), $label);
            }
        }
        // ING-012 : ce qu'un administrateur crée est à lui (plus de commun) ; il fusionne les siens comme tout le monde
        $adminA = $this->createIngredient($admin, 'Poivre');
        $adminB = $this->createIngredient($admin, 'Poivres');
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM ingredient WHERE creator IS NULL AND slug IN ('$adminA', '$adminB')"));
        $this->request('POST', "/api/v1/ingredients/$adminB/merge", $admin, ['into' => $adminA]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM ingredient WHERE slug = '$adminB'"));
    }

    public function testASentListBlocksTheMergeUntilItIsReopened(): void
    {
        // Given : une liste envoyée au drive qui utilise « Tomates »
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $recipe = $this->createRecipe($user, 'Sauce', [[$b, 500]]);
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $this->request('POST', "/api/v1/shopping-lists/$listId/send", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $hash = $this->tablesHash();

        // When
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $this->assertSame(['IN_SENT_LIST', false], [$this->jsonBody()['blockedBy'], $this->jsonBody()['canMerge']]);
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : 409, rien n'a bougé, ni la liste (statut, date d'envoi) ni les recettes
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'INGREDIENT_IN_SENT_LIST']], $this->jsonBody());
        $this->assertSame($hash, $this->tablesHash());

        // When : l'utilisateur rouvre la liste, puis fusionne
        $this->request('POST', "/api/v1/shopping-lists/$listId/reopen", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM shopping_list_row WHERE ingredient = '$b'"));
        $this->assertSame([$a], $this->column('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ?', $listId));
    }

    public function testAnUnitNeitherIngredientCanConvertRefusesTheMergeWithoutWritingAnything(): void
    {
        // Given : « Tomates » est utilisée en tranches dans une recette, mais ni « Tomates » ni « Tomate » ne savent convertir la tranche (une recette
        // ancienne, d'avant le contrôle des unités à l'enregistrement)
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $this->persister->saveUnit(new UnitBuilder()->isSlice()->build());
        $recipe = $this->createRecipe($user, 'Sandwich', [[$b, 4]]);
        $this->entityManager->getConnection()->executeStatement("UPDATE recipe_row SET unit = 'tranche' WHERE recipe = ?", [$recipe]);
        $hash = $this->tablesHash();

        // When : l'aperçu, puis la fusion
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $preview = $this->jsonBody();
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : l'aperçu nomme l'unité, la fusion répond 422 sans rien écrire
        $this->assertSame(['UNIT_MISSING', false, [['slug' => 'tranche', 'name' => 'tranche']], 0], [$preview['blockedBy'], $preview['canMerge'], $preview['missingUnits'], $preview['recoveredConversionCount']]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'MERGE_UNIT_MISSING']], $this->jsonBody());
        $this->assertSame($hash, $this->tablesHash());
        $this->assertSame([[$b, 4.0, 'tranche']], $this->recipeRows($recipe));
    }

    public function testTwoIngredientsEachUsedByOneRecipeEndUpUsedByTwoRecipesAndNoLineIsLost(): void
    {
        // Given : « Melon » et « Melons », chacun dans UNE recette (le retour de l'utilisateur à la recette de la fusion)
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Melon');
        $b = $this->createIngredient($user, 'Melons');
        $r1 = $this->createRecipe($user, 'Salade de melon', [[$a, 300]]);
        $r2 = $this->createRecipe($user, 'Melon au jambon', [[$b, 500]]);

        // When : l'aperçu
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $preview = $this->jsonBody();

        // Then : une recette se déplace, mais « Melon » sera utilisé dans DEUX recettes (et aucune liste) ; rien à récupérer
        $this->assertSame([1, 0, 2, 0, 0, null, true], [$preview['recipeCount'], $preview['listCount'], $preview['resultRecipeCount'], $preview['resultListCount'], $preview['recoveredConversionCount'], $preview['blockedBy'], $preview['canMerge']]);

        // When : la fusion
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : « Melon » est bien utilisé dans deux recettes, celle de « Melons » pointe vers « Melon », aucune ligne n'a disparu
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(2, $this->scalar("SELECT COUNT(DISTINCT recipe) FROM recipe_row WHERE ingredient = '$a'"));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe_row'));
        $this->assertSame([[$a, 300.0, 'gramme']], $this->recipeRows($r1));
        $this->assertSame([[$a, 500.0, 'gramme']], $this->recipeRows($r2));
        $this->request('GET', "/api/v1/recipes/$r2", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['Melon'], array_column($this->jsonBody()['rows'], 'ingredientName'));
    }

    public function testAConversionOfTheAbsorbedIngredientIsRecoveredSoThePlannedQuantityIsConvertedAndShown(): void
    {
        // Given : « Tomates » sait compter les tranches (25 g) et les propose ; « Tomate » ne le sait pas ; une recette de « Tomates » en utilise
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        $this->persister->saveUnit(new UnitBuilder()->isSlice()->build());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($this->persistedIngredient($b))->withStartUnit(new UnitBuilder()->isSlice())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(25)->build());
        $this->entityManager->getConnection()->executeStatement('UPDATE ingredient SET compatible_units = ? WHERE slug = ?', [json_encode(['gramme', 'kilo', 'tranche']), $b]);
        $recipe = $this->createRecipe($user, 'Sandwich', [[$b, 4, 'tranche']]);
        $this->request('GET', "/api/v1/ingredients/$a/units", $user);
        $this->assertSame(['gramme', 'kilo'], array_column($this->jsonBody()['member'], 'slug'));

        // When : l'aperçu — avant, il refusait (UNIT_MISSING)
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $preview = $this->jsonBody();

        // Then : la fusion est possible, une conversion sera récupérée, aucune unité ne manque
        $this->assertSame([null, true, [], 1, 1, 1], [$preview['blockedBy'], $preview['canMerge'], $preview['missingUnits'], $preview['recoveredConversionCount'], $preview['recipeCount'], $preview['resultRecipeCount']]);
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM conversion WHERE ingredient = '$a'"));

        // When
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : « Tomate » a la conversion de la tranche, la recette est sur « Tomate » avec ses 4 tranches
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM conversion WHERE ingredient = '$a' AND start_unit = 'tranche' AND end_unit = 'gramme' AND coefficient = 25"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM conversion WHERE ingredient = '$b'"));
        $this->assertSame([[$a, 4.0, 'tranche']], $this->recipeRows($recipe));
        // l'éditeur de recette propose la tranche pour « Tomate »
        $this->request('GET', "/api/v1/ingredients/$a/units", $user);
        $this->assertSame(['gramme', 'kilo', 'tranche'], array_column($this->jsonBody()['member'], 'slug'));
        // planifier la recette convertit les 4 tranches (100 g) en 0,1 kilo sur la ligne de « Tomate » de la liste ; la recette s'affiche toujours en tranches
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta([$a => 0.1], $this->listRows($listId), 1e-9);
        $this->request('GET', "/api/v1/recipes/$recipe", $user);
        $this->assertSame([['tranche', 4.0]], array_map(static fn(array $row): array => [$row['unitSlug'], (float) $row['quantity']], $this->jsonBody()['rows']));
        // rejouée, la fusion ne copie rien de plus
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM conversion WHERE ingredient = '$a'"));
    }

    public function testTheDriveChoiceOfTheKeptIngredientWinsElseTheAbsorbedOnePassesOverAndThePlanFindsItsProducts(): void
    {
        // Given : un drive configuré ; « Tomates » a un choix (produit 555111), « Tomate » n'en a pas ; une liste envoyée utilisera le plan
        $user = $this->savedUser();
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $this->request('PUT', '/api/v1/drive/choices', $user, $this->choicesBody($b, '555111'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/ingredients/$b/merge-preview?into=$a", $user);
        $this->assertSame(1, $this->jsonBody()['driveChoiceCount']);

        // When
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then : le choix de B est désormais celui de A
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$a], $this->column('SELECT ingredient_slug FROM drive_choice WHERE user_id = ?', $user->getId()));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
        $this->assertSame([$a], array_column($this->jsonBody()['member'], 'ingredientSlug'));

        // Le brouillon d'un envoi resté sur un téléphone envoie encore l'ancien slug : le choix atterrit sur « Tomate », jamais sous « Tomates »
        $this->request('PUT', '/api/v1/drive/choices', $user, $this->choicesBody($b, '555222'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$a], $this->column('SELECT DISTINCT ingredient_slug FROM drive_choice WHERE user_id = ?', $user->getId()));
        $this->request('PUT', "/api/v1/drive/choices/$b", $user, ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => false, 'accepted' => [Product::idFor(Retailer::LECLERC, '123456', '555222')], 'rejected' => []]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame([$a], $this->column('SELECT DISTINCT ingredient_slug FROM drive_choice WHERE user_id = ?', $user->getId()));

        // Given : de nouveau deux ingrédients voisins qui ont chacun leur choix : celui du gardé gagne
        $c = $this->createIngredient($user, 'Poivre');
        $d = $this->createIngredient($user, 'Poivres');
        $this->request('PUT', '/api/v1/drive/choices', $user, $this->choicesBody($c, '777001'));
        $this->request('PUT', '/api/v1/drive/choices', $user, $this->choicesBody($d, '777002'));

        // When
        $this->request('POST', "/api/v1/ingredients/$d/merge", $user, ['into' => $c]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
        $this->assertEqualsCanonicalizing([$a, $c], array_column($this->jsonBody()['member'], 'ingredientSlug'));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM drive_choice_product WHERE product_id LIKE '%777002%'"));
    }

    private function splitInto(User $user, string $part, string $parent, float $ratio = 1.0): void
    {
        $this->request('PUT', "/api/v1/ingredients/$part/parent", $user, ['parent' => $parent, 'ratio' => $ratio]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed> la fiche de l'ingrédient, avec ses parties et son parent
     */
    private function card(User $user, string $slug): array
    {
        $this->request('GET', "/api/v1/ingredients/$slug", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return $this->jsonBody();
    }

    /**
     * @return list<string> les slugs des parties d'un ingrédient, triés
     */
    private function partSlugs(User $user, string $slug): array
    {
        $slugs = array_column($this->card($user, $slug)['parts'], 'slug');
        sort($slugs);

        return $slugs;
    }

    public function testTheMergeCarriesThePartLinkOfTheAbsorbedIngredientToTheKeptOne(): void
    {
        // Given : « Jaune » est une partie d'« Oeuf » à raison de 2 (dans une recette), « Jaunes » est un doublon ordinaire
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf entier');
        $yolk = $this->createIngredient($user, 'Jaune');
        $duplicate = $this->createIngredient($user, 'Jaunes');
        $this->splitInto($user, $yolk, $egg, 2.0);
        $recipe = $this->createRecipe($user, 'Mousse', [[$yolk, 100]]);
        $this->request('GET', "/api/v1/ingredients/$yolk/merge-preview?into=$duplicate", $user);
        $this->assertSame([null, true], [$this->jsonBody()['blockedBy'], $this->jsonBody()['canMerge']]);

        // When
        $this->request('POST', "/api/v1/ingredients/$yolk/merge", $user, ['into' => $duplicate]);

        // Then : « Jaunes » est la partie d'« Oeuf », au même ratio ; « Jaune » a disparu des parties ; la recette suit
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $partOf = $this->card($user, $duplicate)['partOf'];
        $this->assertEquals(['slug' => $egg, 'name' => 'Oeuf entier', 'ratio' => 2], $partOf);
        $this->assertSame([$duplicate], $this->partSlugs($user, $egg));
        $this->assertSame([[$duplicate, 100.0, 'gramme']], $this->recipeRows($recipe));
    }

    public function testTheMergeOfTwoPartsOfTheSameParentKeepsTheRatioOfTheKeptOne(): void
    {
        // Given : deux parties d'« Oeuf », de ratios 1 (gardée) et 4 (absorbée)
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf entier');
        $kept = $this->createIngredient($user, 'Jaune');
        $absorbed = $this->createIngredient($user, 'Jaunes');
        $this->splitInto($user, $kept, $egg, 1.0);
        $this->splitInto($user, $absorbed, $egg, 4.0);

        // When
        $this->request('POST', "/api/v1/ingredients/$absorbed/merge", $user, ['into' => $kept]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$kept], $this->partSlugs($user, $egg));
        $this->assertEquals(1, $this->card($user, $kept)['partOf']['ratio']);
    }

    public function testTheMergeOfAParentGivesItsPartsToTheKeptParent(): void
    {
        // Given : « Oeuf » a une partie, « Oeufs » deux autres (ratios 1, 2, 3) et une autre partie dépend d'un tout autre parent
        $user = $this->savedUser();
        $kept = $this->createIngredient($user, 'Oeuf');
        $absorbed = $this->createIngredient($user, 'Oeufs');
        $citron = $this->createIngredient($user, 'Citron');
        $parts = [];
        foreach ([[$kept, 'Jaune', 1.0], [$absorbed, 'Blanc', 2.0], [$absorbed, 'Coquille', 3.0], [$citron, 'Zeste', 5.0]] as [$parent, $name, $ratio]) {
            $parts[$name] = $this->createIngredient($user, $name);
            $this->splitInto($user, $parts[$name], $parent, $ratio);
        }
        $this->request('GET', "/api/v1/ingredients/$absorbed/merge-preview?into=$kept", $user);
        $this->assertSame([null, true], [$this->jsonBody()['blockedBy'], $this->jsonBody()['canMerge']]);

        // When
        $this->request('POST', "/api/v1/ingredients/$absorbed/merge", $user, ['into' => $kept]);

        // Then : les trois parties sont à « Oeuf », leurs ratios intacts ; celle d'un autre parent n'a pas bougé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $expected = [$parts['Jaune'], $parts['Blanc'], $parts['Coquille']];
        sort($expected);
        $this->assertSame($expected, $this->partSlugs($user, $kept));
        $this->assertEquals([1, 2, 3], array_map(fn(string $name): int|float => $this->card($user, $parts[$name])['partOf']['ratio'], ['Jaune', 'Blanc', 'Coquille']));
        $this->assertSame([$parts['Zeste']], $this->partSlugs($user, $citron));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM ingredient WHERE parent_slug = '$absorbed'"));
    }

    public function testAMergeThatWouldBreakTheSplitIsRefusedWithItsCodeAndWritesNothing(): void
    {
        // Given : « Oeuf » (parent de « Jaune »), « Citron » (parent de « Zeste »), et un ingrédient ordinaire
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf');
        $citron = $this->createIngredient($user, 'Citron');
        $yolk = $this->createIngredient($user, 'Jaune');
        $zest = $this->createIngredient($user, 'Zeste');
        $this->splitInto($user, $yolk, $egg);
        $this->splitInto($user, $zest, $citron);
        $hash = $this->tablesHash();

        /** @var array<string, array{string, string}> $cases `[absorbé, gardé]` */
        $cases = [
            'le parent absorbé dans l\'une de ses parties' => [$egg, $yolk],
            'une partie absorbée dans son parent' => [$yolk, $egg],
            'deux parties de deux parents différents' => [$yolk, $zest],
            'un parent absorbé dans une partie d\'un autre parent' => [$egg, $zest],
            'une partie absorbée dans un parent qui a des parties' => [$zest, $egg],
        ];
        foreach ($cases as $label => [$absorbed, $kept]) {
            // When
            $this->request('GET', "/api/v1/ingredients/$absorbed/merge-preview?into=$kept", $user);
            $preview = $this->jsonBody();
            $this->request('POST', "/api/v1/ingredients/$absorbed/merge", $user, ['into' => $kept]);

            // Then : l'aperçu et l'écriture disent la même chose, rien n'est écrit
            $this->assertSame(['PART_CONFLICT', false], [$preview['blockedBy'], $preview['canMerge']], $label);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $label);
            $this->assertSame(['error' => ['message' => 'MERGE_PART_CONFLICT']], $this->jsonBody(), $label);
            $this->assertSame($hash, $this->tablesHash(), $label);
        }
    }

    public function testTheRegistryOfAnOpenListBlocksTheMergeUntilTheMealIsTakenOffThePlan(): void
    {
        // Given : « Jaune » (partie d'« Oeuf ») est dans une recette planifiée : la liste porte « Oeuf » et son registre nomme « Jaune » ; « Jaunes » est ordinaire
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf');
        $yolk = $this->createIngredient($user, 'Jaune');
        $duplicate = $this->createIngredient($user, 'Jaunes');
        $this->splitInto($user, $yolk, $egg);
        $recipe = $this->createRecipe($user, 'Mousse', [[$yolk, 200]]);
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame([$egg], array_keys($this->listRows($listId)));
        $hash = $this->tablesHash();

        // When : l'aperçu, puis la fusion
        $this->request('GET', "/api/v1/ingredients/$yolk/merge-preview?into=$duplicate", $user);
        $preview = $this->jsonBody();
        $this->request('POST', "/api/v1/ingredients/$yolk/merge", $user, ['into' => $duplicate]);

        // Then : 409, rien n'a bougé, le registre de la liste moins que tout
        $this->assertSame(['PART_IN_LIST', false], [$preview['blockedBy'], $preview['canMerge']]);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'MERGE_PART_IN_LIST']], $this->jsonBody());
        $this->assertSame($hash, $this->tablesHash());

        // When : l'utilisateur retire le repas de la liste, puis fusionne
        $this->request('DELETE', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/ingredients/$yolk/merge", $user, ['into' => $duplicate]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($egg, $this->card($user, $duplicate)['partOf']['slug']);
    }

    public function testAnOrdinaryMergeIsNotStoppedByTheRegistryOfAnotherIngredient(): void
    {
        // Given : le registre d'une liste nomme « Jaune » et « Oeuf » ; on fusionne deux autres ingrédients
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf');
        $yolk = $this->createIngredient($user, 'Jaune');
        $this->splitInto($user, $yolk, $egg);
        $recipe = $this->createRecipe($user, 'Mousse', [[$yolk, 200]]);
        $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $a = $this->createIngredient($user, 'Tomate');
        $b = $this->createIngredient($user, 'Tomates');

        // When
        $this->request('POST', "/api/v1/ingredients/$b/merge", $user, ['into' => $a]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testRemovingAParentDetachesItsPartsAndTheRegistryOfAnOpenListBlocksTheRemoval(): void
    {
        // Given : « Jaune » et « Blanc » sont deux parties d'« Oeuf »
        $user = $this->savedUser();
        $egg = $this->createIngredient($user, 'Oeuf');
        $yolk = $this->createIngredient($user, 'Jaune');
        $white = $this->createIngredient($user, 'Blanc');
        $this->splitInto($user, $yolk, $egg);
        $this->splitInto($user, $white, $egg, 2.0);

        // When : on supprime le parent
        $this->request('DELETE', "/api/v1/ingredients/$egg", $user);

        // Then : les deux parties sont devenues ordinaires, avec leur fiche
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        foreach ([$yolk, $white] as $part) {
            $card = $this->card($user, $part);
            $this->assertSame([null, []], [$card['partOf'], $card['parts']], $part);
        }

        // Given : de nouveau un parent, une partie planifiée dans une recette puis retirée de la recette (le registre de la liste la nomme encore)
        $parent = $this->createIngredient($user, 'Citron');
        $part = $this->createIngredient($user, 'Zeste');
        $this->splitInto($user, $part, $parent);
        $recipe = $this->createRecipe($user, 'Tarte', [[$part, 200]]);
        $listId = $this->savedActiveList($user);
        $this->request('PUT', "/api/v1/shopping-lists/active/recipes/$recipe", $user);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM recipe_row WHERE recipe = ?', [$recipe]);
        $hash = $this->tablesHash();

        // When : on supprime la partie, puis le parent
        $responses = [];
        foreach ([$part, $parent] as $slug) {
            $this->request('DELETE', "/api/v1/ingredients/$slug", $user);
            $responses[$slug] = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['error']['message'] ?? null];
        }

        // Then : refusé comme un ingrédient d'une liste, ni l'un ni l'autre n'est supprimé, rien n'est détaché
        $this->assertSame([409, 409], array_column($responses, 0));
        $this->assertStringContainsString('exist in shopping list', (string) $responses[$part][1]);
        $this->assertStringContainsString('exist in shopping list', (string) $responses[$parent][1]);
        $this->assertSame($hash, $this->tablesHash());

        // When : la liste est terminée
        $this->request('POST', "/api/v1/shopping-lists/$listId/complete", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('DELETE', "/api/v1/ingredients/$parent", $user);

        // Then : le registre d'une liste terminée n'empêche plus rien, la partie est détachée
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->card($user, $part)['partOf']);
    }

    private function choicesBody(string $ingredientSlug, string $retailerProductId): array
    {
        return ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => $ingredientSlug, 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => $retailerProductId, 'label' => 'Tomate ' . $retailerProductId]],
        ]];
    }

    private function createIngredient(User $user, string $name): string
    {
        $this->request('POST', '/api/v1/ingredients', $user, ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    private function persistedIngredient(string $slug): \Repas\Repas\Domain\Model\Ingredient
    {
        $this->entityManager->clear();

        return static::getContainer()->get(\Repas\Repas\Domain\Interface\IngredientRepository::class)->findOneBySlug($slug);
    }

    /**
     * @param list<array{string, int}|array{string, int, string}> $rows `[slug, quantité, unité]` (le gramme par défaut)
     */
    private function createRecipe(User $user, string $name, array $rows): string
    {
        $this->request('POST', '/api/v1/recipes', $user, [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => array_map(static fn(array $row): array => ['ingredientSlug' => $row[0], 'unitSlug' => $row[2] ?? 'gramme', 'quantity' => $row[1]], $rows),
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    private function savedActiveList(User $owner): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * @return array<string, float> la quantité de chaque ligne calculée de la liste, par ingrédient
     */
    private function listRows(string $listId): array
    {
        return array_map('floatval', $this->entityManager->getConnection()->fetchAllKeyValue('SELECT ingredient, quantity FROM shopping_list_row WHERE shopping_list = ?', [$listId]));
    }

    /**
     * @return list<array{string, float, string}>
     */
    private function listItems(string $listId): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], (float) $row[1], $row[2]],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT ingredient, quantity, unit FROM shopping_list_ingredient WHERE shopping_list = ? ORDER BY ingredient', [$listId]),
        );
    }

    /**
     * @return list<array{string, float, string}>
     */
    private function recipeRows(string $recipeId): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], (float) $row[1], $row[2]],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT ingredient, quantity, unit FROM recipe_row WHERE recipe = ? ORDER BY position', [$recipeId]),
        );
    }

    private function tablesHash(): string
    {
        $this->entityManager->clear();
        $hashes = [];
        foreach (['ingredient', 'conversion', 'ingredient_alias', 'recipe', 'recipe_row', 'shopping_list', 'shopping_list_row', 'shopping_list_ingredient', 'drive_choice', 'drive_choice_product', '"user"'] as $table) {
            $hashes[] = $this->entityManager->getConnection()->fetchOne(sprintf("SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t::text COLLATE \"C\"), '')) FROM %s t", $table));
        }

        return md5(implode('|', $hashes));
    }

    private function createdIngredients(User $user): int
    {
        $this->entityManager->clear();

        return (int) (static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients'] ?? 0);
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

    /**
     * @return list<string>
     */
    private function column(string $sql, string ...$params): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn($sql, $params);
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}

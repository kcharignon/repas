<?php

namespace Repas\Tests\Drive\Acl;

use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\ShoppingListForDrive;
use Repas\Drive\Domain\Model\ShoppingListLineForDrive;
use Repas\Drive\Infrastructure\Acl\IngredientNameQueryAdapter;
use Repas\Drive\Infrastructure\Acl\ShoppingListQueryAdapter;
use Repas\Repas\Application\OpenHostService\IngredientNameQueryOHS;
use Repas\Repas\Application\OpenHostService\ShoppingListContentQueryOHS;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Tests\Helper\InMemoryLoader\IngredientNameInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListContentInMemoryLoader;
use Repas\Tests\Helper\RepasForDrive;

/**
 * La transposition de ce que le contexte `Repas` publie (traduction des slugs d'ingrédients, rayons, unités, noms, contenu d'une liste de
 * courses) en réponses du contexte `Drive` (ADR 0035), avec les vrais Open Host Services branchés sur les doubles en mémoire de `Repas`. Les
 * handlers d'écriture du `Drive` exercent aussi les adapters d'écriture par transitivité (`tests/Drive/Application`) ; le SQL est prouvé sur la
 * vraie base (`tests/Repas/OutputAdapter`).
 */
class RepasAdaptersTest extends TestCase
{
    public function testTheShoppingListOfRepasBecomesTheListOfTheDriveLineByLine(): void
    {
        // Given : une ligne dont l'unité se mesure, une autre qui ne se convertit pas (aucune dimension) et sans rayon connu
        $list = new ShoppingListForDrive('list-1', 'user-1', 'SENT', 7, [
            new ShoppingListLineForDrive('farine', 'Farine', 300.0, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0, 'boulangerie'),
            new ShoppingListLineForDrive('sel', 'Sel', 2.0, 'cuillere-a-soupe', 'cuillère à soupe', 'cuillère à soupe', null, null),
        ]);
        $loader = new ShoppingListContentInMemoryLoader($list);

        // When
        $result = new ShoppingListQueryAdapter(new ShoppingListContentQueryOHS($loader))->get('list-1');

        // Then
        $this->assertEquals($list, $result);
        $this->assertSame(['list-1'], $loader->getCalls);
    }

    public function testAnUnknownShoppingListKeepsTheNotFoundOfRepas(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('inconnue'));

        // When
        new ShoppingListQueryAdapter(new ShoppingListContentQueryOHS(new ShoppingListContentInMemoryLoader()))->get('inconnue');
    }

    public function testTheNamesAskedForTheUserComeBackAsTheyAre(): void
    {
        // Given
        $names = new IngredientNameInMemoryLoader(['farine' => 'Farine']);

        // When
        $result = new IngredientNameQueryAdapter(new IngredientNameQueryOHS($names))->namesBySlug('user-1', ['farine', 'sel']);

        // Then
        $this->assertSame(['farine' => 'Farine'], $result);
        $this->assertSame([['userId' => 'user-1', 'slugs' => ['farine', 'sel']]], $names->namesBySlugCalls);
    }

    public function testTheWriteAdaptersAskRepasForAliasesDepartmentsAndUnits(): void
    {
        // Given : l'utilisateur a adopté la farine ; « sel » est un ingrédient à lui, « pain-de-autrui » à un autre compte
        $repas = new RepasForDrive(aliases: ['farine' => 'farine-user-1'], notOwned: ['pain-de-autrui'], departments: ['farine-user-1' => 'boulangerie'], units: ['gramme']);

        // When / Then : la traduction ne rend que les alias, la vérification d'appartenance y ajoute l'ingrédient lui-même
        $this->assertSame(['farine' => 'farine-user-1'], $repas->aliasPort()->resolve('user-1', ['farine', 'sel']));
        $this->assertSame(['farine' => 'farine-user-1', 'sel' => 'sel'], $repas->aliasPort()->resolveOwned('user-1', ['farine', 'sel', 'pain-de-autrui']));
        $this->assertSame(['farine-user-1' => 'boulangerie'], $repas->departmentPort()->departmentSlugsByIngredient(['farine-user-1', 'inconnu']));
        $this->assertSame(['gramme'], $repas->unitPort()->existingSlugs(['gramme', 'tranche']));
    }
}

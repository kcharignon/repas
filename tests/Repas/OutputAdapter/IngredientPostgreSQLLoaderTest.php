<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\GetIngredient\ListDestinationDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\DepartmentIcon;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les lectures d'ingrédients de l'utilisateur sur la vraie base (ADR 0028, amendement du 2026-10-20) : il ne voit que les siens, sans exception
 * pour un administrateur ; `canEdit` est donc toujours vrai. La règle de visibilité elle-même, pour toutes les lectures, est prouvée par
 * `IngredientVisibilityTest`.
 */
class IngredientPostgreSQLLoaderTest extends DatabaseTestCase
{
    private IngredientLoader $ingredientLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientLoader = static::getContainer()->get(IngredientLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testListByDepartmentReturnsTheDepartmentAndTheOwnIngredientsSortedByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $rice = $this->savedIngredient(new IngredientBuilder()->withName('zzz riz')->withDepartment($department)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->withCreator($viewer, true));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('aaa blé')->withDepartment($department)->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('mmm orge')->withDepartment($department)->withCreator($other, true));
        $this->savedIngredient(new IngredientBuilder()->withName('bbb autre rayon')->withDepartment(new DepartmentBuilder()->isBakery())->withCreator($viewer, true));

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $viewer->getId());

        // Then : ni celui d'un autre, ni celui d'un autre rayon ; tout est modifiable par son propriétaire
        $this->assertEquals(new DepartmentDTO('cereale', 'céréale', new DepartmentBuilder()->isCereal()->build()->getImage(), null), $result->department);
        $this->assertEquals(
            [
                new IngredientRowDTO($own->getSlug(), 'aaa blé', '', true, false),
                new IngredientRowDTO($rice->getSlug(), 'zzz riz', 'g', true, false),
            ],
            $result->ingredients,
        );
    }

    public function testListByDepartmentShowsAnAdministratorHisOwnIngredientsOnly(): void
    {
        // Given : l'administrateur a le sien, un autre compte (le propriétaire du catalogue, par exemple) a les siens
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser(admin: true);
        $department = new DepartmentBuilder()->isCereal();
        $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment($department)->withCreator($other, true));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment($department)->withCreator($admin, true));
        $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withDepartment($department)->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $admin->getId());

        // Then
        $this->assertSame(
            [[$own->getSlug(), true]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->canEdit], $result->ingredients),
        );
    }

    public function testListByDepartmentSaysTheIconOfTheDepartment(): void
    {
        // Given : un rayon dont l'icône est réglée, et un autre qui n'en a pas
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('aaa blé')->withDepartment(new DepartmentBuilder()->isCereal()->withIcon(DepartmentIcon::CAN))->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('aaa orge')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer, true));

        // When / Then
        $this->assertSame('can', $this->ingredientLoader->listByDepartment('cereale', $viewer->getId())->department->icon);
        $this->assertNull($this->ingredientLoader->listByDepartment('viande', $viewer->getId())->department->icon);
    }

    public function testListByDepartmentReturnsAnEmptyListForADepartmentWithoutIngredient(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $viewer->getId());

        // Then
        $this->assertSame([], $result->ingredients);
    }

    public function testListByDepartmentFailsForAnUnknownDepartment(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());

        // When
        $this->ingredientLoader->listByDepartment('inconnu', $this->savedUser()->getId());
    }

    public function testGetReturnsTheIngredientWithItsDepartmentAndUnits(): void
    {
        // Given
        $viewer = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz farine')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme()));

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

        // Then
        $this->assertEquals(new IngredientDTO(
            slug: $ingredient->getSlug(),
            name: 'zzz farine',
            image: $ingredient->getImage(),
            departmentSlug: 'cereale',
            departmentName: 'céréale',
            cookingUnitSlug: 'gramme',
            cookingUnitName: 'gramme',
            purchaseUnitSlug: 'gramme',
            purchaseUnitName: 'gramme',
            purchaseUnitSymbol: 'g',
            hasSameUnit: true,
            coefficient: null,
            creatorId: $viewer->getId(),
            canEdit: true,
            recipeCount: 0,
            listCount: 0,
            isBase: false,
            canSetBase: false,
            listDestination: new ListDestinationDTO(ListDestinationDTO::DEFAULT, null, null),
        ), $result);
    }

    // --- Le choix de liste du produit (ADR 0036) : lu avec le nom du type, `DEFAULT` quand il n'a pas ou plus d'objet

    public function testGetReadsTheListDestinationInEachMode(): void
    {
        // Given : un type de liste du lecteur, et trois produits (aucun choix, la liste principale, le type)
        $viewer = $this->savedUser();
        $this->persister->saveShoppingListType(ShoppingListType::create('type-1', $viewer->getId(), 'Boucherie', new \DateTimeImmutable()));
        $free = $this->savedIngredient(new IngredientBuilder()->withName('zzz libre')->withCreator($viewer, true));
        $main = $this->savedIngredient(new IngredientBuilder()->withName('zzz principale')->withCreator($viewer, true)->withListDestination('main'));
        $typed = $this->savedIngredient(new IngredientBuilder()->withName('zzz typé')->withCreator($viewer, true)->withListDestination('type-1'));

        // When
        $read = fn(Ingredient $ingredient): ListDestinationDTO => $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId())->listDestination;

        // Then : les trois clés sont toujours là
        $this->assertEquals(new ListDestinationDTO('DEFAULT', null, null), $read($free));
        $this->assertEquals(new ListDestinationDTO('MAIN', null, null), $read($main));
        $this->assertEquals(new ListDestinationDTO('TYPE', 'type-1', 'Boucherie'), $read($typed));
    }

    public function testGetReadsADestinationThatDesignatesAMissingOrForeignTypeAsDefault(): void
    {
        // Given : un choix resté en base alors que son type a disparu, et un choix qui désigne le type d'un AUTRE compte
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $this->persister->saveShoppingListType(ShoppingListType::create('their-type', $other->getId(), 'Marché', new \DateTimeImmutable()));
        $gone = $this->savedIngredient(new IngredientBuilder()->withName('zzz disparu')->withCreator($viewer, true)->withListDestination('gone-type'));
        $foreign = $this->savedIngredient(new IngredientBuilder()->withName('zzz étranger')->withCreator($viewer, true)->withListDestination('their-type'));

        // When / Then : jamais le nom du type d'un autre, jamais d'erreur
        foreach ([$gone, $foreign] as $ingredient) {
            $this->assertEquals(new ListDestinationDTO('DEFAULT', null, null), $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId())->listDestination);
        }
    }

    public function testGetReadsTheListDestinationInTheSameQueryCountWhateverItsMode(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->persister->saveShoppingListType(ShoppingListType::create('type-1', $viewer->getId(), 'Boucherie', new \DateTimeImmutable()));
        $free = $this->savedIngredient(new IngredientBuilder()->withName('zzz libre')->withCreator($viewer, true));
        $typed = $this->savedIngredient(new IngredientBuilder()->withName('zzz typé')->withCreator($viewer, true)->withListDestination('type-1'));
        $counter = static::getContainer()->get(QueryCounter::class);
        $count = function (Ingredient $ingredient) use ($counter, $viewer): int {
            $this->entityManager->clear();
            $counter->reset();
            $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

            return $counter->count();
        };

        // When / Then : le nom du type vient de la même requête
        $this->assertSame($count($free), $count($typed));
    }

    public function testGetCountsTheRecipesAndTheListsOfTheViewerThatUseTheIngredient(): void
    {
        // Given : l'ingrédient est dans deux recettes du lecteur (dont une avec deux lignes), une liste (une ligne ET un ajout à la main), une
        // autre liste (un ajout à la main) ; une recette et une liste d'un autre utilisateur ne comptent pas
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz farine')->withCreator($viewer, true));
        $this->usage($ingredient->getSlug(), $viewer->getId(), $other->getId());

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

        // Then
        $this->assertSame([2, 2], [$result->recipeCount, $result->listCount]);
    }

    public function testGetCountsNothingWhenOnlyAnotherUserUsesTheIngredient(): void
    {
        // Given : un ingrédient du lecteur que seules des lignes d'un autre utilisateur désignent (donnée héritée d'avant l'ADR 0021)
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $own = $this->savedIngredient(new IngredientBuilder()->withName('zzz riz')->withCreator($viewer, true));
        $this->usage($own->getSlug(), $other->getId(), $other->getId());

        // When
        $result = $this->ingredientLoader->get($own->getSlug(), $viewer->getId());

        // Then
        $this->assertSame([0, 0], [$result->recipeCount, $result->listCount]);
    }

    /**
     * L'ingrédient est dans deux recettes de `$userId` (une avec deux lignes), une liste (une ligne et un ajout à la main) et une autre liste
     * (un ajout à la main) ; `$otherId` n'a que le bruit : une recette et une liste qui l'utilisent aussi.
     */
    private function usage(string $slug, string $userId, string $otherId): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ([[$userId, 'r1', 2], [$userId, 'r2', 1], [$otherId, 'r3', 1]] as [$author, $recipe, $rows]) {
            $connection->insert('recipe', ['id' => $recipe, 'name' => $recipe, 'slug' => $recipe . $author, 'serving' => 4, 'author' => $author, 'type' => 'plat', 'orignal' => null, 'version' => 1]);
            for ($position = 0; $position < $rows; ++$position) {
                $connection->insert('recipe_row', ['id' => $recipe . '-' . $position, 'ingredient' => $slug, 'quantity' => 1, 'unit' => 'gramme', 'recipe' => $recipe, 'position' => $position]);
            }
        }
        foreach ([[$userId, 'l1'], [$userId, 'l2'], [$otherId, 'l3']] as [$owner, $list]) {
            $connection->insert('shopping_list', ['id' => $list, 'owner' => $owner, 'created_at' => '2026-10-01 10:00:00', 'status' => 'ACTIVE', 'name' => null, 'sent_at' => null, 'version' => 1]);
        }
        $connection->insert('shopping_list_row', ['id' => 'x1', 'shopping_list' => 'l1', 'ingredient' => $slug, 'quantity' => 1, 'unit' => 'gramme', 'checked' => 'f']);
        $connection->insert('shopping_list_ingredient', ['id' => 'y1', 'shopping_list' => 'l1', 'ingredient' => $slug, 'unit' => 'gramme', 'quantity' => 1]);
        $connection->insert('shopping_list_ingredient', ['id' => 'y2', 'shopping_list' => 'l2', 'ingredient' => $slug, 'unit' => 'gramme', 'quantity' => 1]);
        $connection->insert('shopping_list_row', ['id' => 'x3', 'shopping_list' => 'l3', 'ingredient' => $slug, 'quantity' => 1, 'unit' => 'gramme', 'checked' => 'f']);
    }

    public function testGetFindsTheCoefficientFromPurchaseUnitToCookingUnit(): void
    {
        // Given
        $viewer = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz beurre')->withCreator($viewer, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isBlock()));
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isBlock()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build());

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

        // Then
        $this->assertFalse($result->hasSameUnit);
        $this->assertSame(250.0, $result->coefficient);
    }

    public function testGetPrefersTheConversionOfTheIngredientToTheCommonOne(): void
    {
        // Given
        $viewer = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz sucre')->withCreator($viewer, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo()));
        $this->persister->saveConversion(new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(1000)->build());

        // When : seule la conversion générale existe
        $common = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(990)->build());
        $specific = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

        // Then
        $this->assertSame([1000.0, 990.0], [$common->coefficient, $specific->coefficient]);
    }

    public function testGetHasNoCoefficientWithoutAnyConversion(): void
    {
        // Given
        $viewer = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz lait')->withCreator($viewer, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isLiter()));

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());

        // Then
        $this->assertNull($result->coefficient);
    }

    public function testGetCanAlwaysEditWhatItShows(): void
    {
        // Given
        $viewer = $this->savedUser(admin: true);
        $own = $this->savedIngredient(new IngredientBuilder()->withName('zzz sel')->withCreator($viewer, true));

        // When
        $result = $this->ingredientLoader->get($own->getSlug(), $viewer->getId());

        // Then : un administrateur n'est pas un cas à part
        $this->assertSame([true, $viewer->getId()], [$result->canEdit, $result->creatorId]);
    }

    public function testGetHidesAnIngredientOfAnotherUserEvenFromAnAdministrator(): void
    {
        // Given
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz secret')->withCreator($owner, true));
        $admin = $this->savedUser(admin: true);

        // Then : pas de passe-droit (D3)
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));

        // When
        $this->ingredientLoader->get($ingredient->getSlug(), $admin->getId());
    }

    public function testGetFailsForAnUnknownIngredient(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->ingredientLoader->get('inconnu', $this->savedUser()->getId());
    }

    public function testListCompatibleUnitsKeepsTheStoredOrderWithCapitalizedNames(): void
    {
        // Given
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz farine')
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())
            ->withCompatibleUnits([new UnitBuilder()->isKilo(), new UnitBuilder()->isGramme(), new UnitBuilder()->isLiter()]));

        // When
        $result = $this->ingredientLoader->listCompatibleUnits($ingredient->getSlug());

        // Then
        $this->assertEquals(
            array_map(static fn($unit): CompatibleUnitDTO => new CompatibleUnitDTO($unit->getSlug(), ucfirst($unit->getName())), $ingredient->getCompatibleUnits()->toArray()),
            $result,
        );
        $this->assertContains('Kilo', array_map(static fn(CompatibleUnitDTO $unit): string => $unit->name, $result));
        $this->assertCount(3, $result);
    }

    public function testListCompatibleUnitsFailsForAnUnknownIngredient(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->ingredientLoader->listCompatibleUnits('inconnu');
    }

    public function testListAvailableReturnsTheOwnIngredientsSortedByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $salt = $this->savedIngredient(new IngredientBuilder()->withName('mmm sel')->withCreator($viewer, true));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('aaa farine')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz secret')->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->listAvailable($viewer->getId());

        // Then
        $this->assertEquals(
            [new IngredientOptionDTO($own->getSlug(), 'aaa farine', 'conserve', 'conserve'), new IngredientOptionDTO($salt->getSlug(), 'mmm sel', 'conserve', 'conserve')],
            $result,
        );
    }

    public function testListAvailableSearchesTheNameWithoutCaseNorAccentNorSpacing(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $cream = $this->savedIngredient(new IngredientBuilder()->withName('zzz Crème fraîche')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz farine')->withCreator($viewer, true));
        $ownCream = $this->savedIngredient(new IngredientBuilder()->withName('aaa crème maison')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz crème secrète')->withCreator($other, true));

        // When
        $byAccentedText = $this->ingredientLoader->listAvailable($viewer->getId(), '  CRÈME ');
        $byPartOfTwoWords = $this->ingredientLoader->listAvailable($viewer->getId(), 'me fra');

        // Then : les siens, jamais ceux d'un autre, toujours triés par nom
        $this->assertEquals(
            [new IngredientOptionDTO($ownCream->getSlug(), 'aaa crème maison', 'conserve', 'conserve'), new IngredientOptionDTO($cream->getSlug(), 'zzz Crème fraîche', 'conserve', 'conserve')],
            $byAccentedText,
        );
        $this->assertEquals([new IngredientOptionDTO($cream->getSlug(), 'zzz Crème fraîche', 'conserve', 'conserve')], $byPartOfTwoWords);
    }

    public function testListAvailableWithABlankSearchKeepsEverything(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('zzz sel')->withCreator($viewer, true));

        // When
        $result = $this->ingredientLoader->listAvailable($viewer->getId(), '   ');

        // Then
        $this->assertCount(1, $result);
    }

    public function testListAvailableIsEmptyWithoutIngredient(): void
    {
        // When
        $result = $this->ingredientLoader->listAvailable($this->savedUser()->getId());

        // Then
        $this->assertSame([], $result);
    }

    public function testSearchReturnsTheMostRelevantFirstWithTheDepartmentOfEachIngredient(): void
    {
        // Given
        $viewer = $this->savedUser();
        $cereal = new DepartmentBuilder()->isCereal();
        $exact = $this->savedIngredient(new IngredientBuilder()->withName('Riz')->withDepartment($cereal)->withCreator($viewer, true));
        $longer = $this->savedIngredient(new IngredientBuilder()->withName('Riz basmati')->withDepartment(new DepartmentBuilder()->isBakery())->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Chorizo')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer, true));

        // When
        $result = $this->ingredientLoader->search('  RIZ ', $viewer->getId());

        // Then
        $this->assertEquals(
            [
                new IngredientSearchResultDTO($exact->getSlug(), 'Riz', '', true, 'cereale', 'céréale', new DepartmentBuilder()->isCereal()->build()->getImage()),
                new IngredientSearchResultDTO($longer->getSlug(), 'Riz basmati', '', true, 'boulangerie', 'boulangerie', new DepartmentBuilder()->isBakery()->build()->getImage()),
            ],
            $result,
        );
    }

    public function testSearchForgivesATypoAndIgnoresAccents(): void
    {
        // Given
        $viewer = $this->savedUser();
        $cream = $this->savedIngredient(new IngredientBuilder()->withName('Crème fraîche')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Caramel')->withCreator($viewer, true));

        // When
        $byTypo = $this->ingredientLoader->search('creme frache', $viewer->getId());
        $byAccent = $this->ingredientLoader->search('CRÈME', $viewer->getId());

        // Then
        $this->assertSame([$cream->getSlug()], array_map(static fn(IngredientSearchResultDTO $result): string => $result->slug, $byTypo));
        $this->assertSame([$cream->getSlug()], array_map(static fn(IngredientSearchResultDTO $result): string => $result->slug, $byAccent));
    }

    public function testSearchBreaksTiesByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Tomate séchée')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Tomate cerise')->withCreator($viewer, true));

        // When
        $result = $this->ingredientLoader->search('tomate', $viewer->getId());

        // Then : mêmes mots, même longueur de nom
        $this->assertSame(['Tomate cerise', 'Tomate séchée'], array_map(static fn(IngredientSearchResultDTO $result): string => $result->name, $result));
    }

    public function testSearchFindsTheOwnIngredientsNeverThoseOfAnotherUser(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser(admin: true);
        $this->savedIngredient(new IngredientBuilder()->withName('Sel')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel fin')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel secret')->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->search('sel', $viewer->getId());

        // Then
        $this->assertSame(['Sel', 'Sel fin'], array_map(static fn(IngredientSearchResultDTO $result): string => $result->name, $result));
    }

    public function testSearchByAnAdministratorFindsHisOwnIngredientsOnlyAndAllEditable(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser(admin: true);
        $this->savedIngredient(new IngredientBuilder()->withName('Sel')->withCreator($other, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel fin')->withCreator($admin, true));

        // When
        $result = $this->ingredientLoader->search('sel', $admin->getId());

        // Then
        $this->assertSame(['Sel fin'], array_map(static fn(IngredientSearchResultDTO $result): string => $result->name, $result));
        $this->assertSame([true], array_map(static fn(IngredientSearchResultDTO $result): bool => $result->canEdit, $result));
    }

    public function testSearchKeepsTheThirtyMostRelevantResults(): void
    {
        // Given
        $viewer = $this->savedUser();
        for ($i = 0; $i < 35; $i++) {
            $this->savedIngredient(new IngredientBuilder()->withName('Fromage ' . $i)->withCreator($viewer, true));
        }

        // When
        $result = $this->ingredientLoader->search('fromage', $viewer->getId());

        // Then
        $this->assertCount(30, $result);
    }

    public function testSearchFindsNothingForAnUnrelatedOrTextWithoutLetter(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Sel')->withCreator($viewer, true));

        // When
        $unrelated = $this->ingredientLoader->search('zzzzzz', $viewer->getId());
        $withoutLetter = $this->ingredientLoader->search(' !? ', $viewer->getId());

        // Then
        $this->assertSame([], $unrelated);
        $this->assertSame([], $withoutLetter);
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }


    // --- Le même nom dans deux rayons chez le même propriétaire (ADR 0028, amendement du 2026-10-30, ING-016) : aucune lecture ne les mélange

    public function testTheSameNameInTwoDepartmentsIsFoundTwiceBySearchEachWithItsOwnDepartment(): void
    {
        // Given : « Petits pois » en céréale et en viande (rayons de test), plus un autre ingrédient qui y ressemble
        $viewer = $this->savedUser();
        $first = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-a')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer));
        $second = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-b')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer));

        // When
        $result = $this->ingredientLoader->search('petits pois', $viewer->getId());

        // Then : les deux, jamais l'un à la place de l'autre, ordre déterministe (même pertinence, même nom : le slug), chacun avec son rayon
        $this->assertSame([$first->getSlug(), $second->getSlug()], array_map(static fn(IngredientSearchResultDTO $item): string => $item->slug, $result));
        $this->assertSame(['cereale', 'viande'], array_map(static fn(IngredientSearchResultDTO $item): string => $item->departmentSlug, $result));
        $this->assertSame(['céréale', 'viande'], array_map(static fn(IngredientSearchResultDTO $item): string => $item->departmentName, $result));
    }

    public function testTheSameNameInTwoDepartmentsIsListedOnceInEachDepartment(): void
    {
        // Given
        $viewer = $this->savedUser();
        $first = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-a')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer));
        $second = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-b')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer));

        // When
        $inCereal = $this->ingredientLoader->listByDepartment('cereale', $viewer->getId());
        $inMeat = $this->ingredientLoader->listByDepartment('viande', $viewer->getId());

        // Then : chaque rayon montre le sien seulement
        $this->assertSame([$first->getSlug()], array_map(static fn(IngredientRowDTO $row): string => $row->slug, $inCereal->ingredients));
        $this->assertSame([$second->getSlug()], array_map(static fn(IngredientRowDTO $row): string => $row->slug, $inMeat->ingredients));
    }

    public function testTheSameNameInTwoDepartmentsIsOfferedTwiceToChooseAnIngredient(): void
    {
        // Given
        $viewer = $this->savedUser();
        $first = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-a')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer));
        $second = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-b')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer));

        // When
        $result = $this->ingredientLoader->listAvailable($viewer->getId(), 'petits pois');

        // Then
        $this->assertSame([$first->getSlug(), $second->getSlug()], array_map(static fn(IngredientOptionDTO $option): string => $option->slug, $result));
        // et chacun dit son rayon : le front les distingue (ING-016 B)
        $this->assertSame([['cereale', 'céréale'], ['viande', 'viande']], array_map(static fn(IngredientOptionDTO $option): array => [$option->departmentSlug, $option->departmentName], $result));
    }

    public function testGetShowsEachOfTwoIngredientsOfTheSameNameWithItsOwnDepartment(): void
    {
        // Given
        $viewer = $this->savedUser();
        $first = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-a')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer));
        $second = $this->savedIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-b')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($viewer));

        // When / Then
        $this->assertSame('cereale', $this->ingredientLoader->get($first->getSlug(), $viewer->getId())->departmentSlug);
        $this->assertSame('viande', $this->ingredientLoader->get($second->getSlug(), $viewer->getId())->departmentSlug);
    }

    private function savedIngredient(IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    // --- Le marqueur « de base » (ADR 0028, amendement du 2026-10-20) : montré au seul propriétaire du catalogue

    private function savedCatalogOwner(): User
    {
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->persister->saveUser($owner);

        return $owner;
    }

    public function testListByDepartmentShowsTheBaseMarkerToTheCatalogOwnerOnly(): void
    {
        // Given : le propriétaire a un ingrédient de base et un autre qui ne l'est pas ; un utilisateur ordinaire a aussi un ingrédient marqué
        // (inerte : posé ailleurs que chez le propriétaire)
        $owner = $this->savedCatalogOwner();
        $user = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $ownersBase = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment($department)->withCreator($owner, true)->withBase());
        $ownersOther = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment($department)->withCreator($owner, true));
        $usersStray = $this->savedIngredient(new IngredientBuilder()->withName('ddd seigle')->withDepartment($department)->withCreator($user, true)->withBase());

        // When
        $seenByOwner = $this->ingredientLoader->listByDepartment('cereale', $owner->getId());
        $seenByUser = $this->ingredientLoader->listByDepartment('cereale', $user->getId());

        // Then : le propriétaire voit son classement ; l'utilisateur ne voit que le sien et aucun marqueur, pas même celui qu'il aurait posé
        $this->assertSame(
            [[$ownersBase->getSlug(), true], [$ownersOther->getSlug(), false]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $seenByOwner->ingredients),
        );
        $this->assertSame(
            [[$usersStray->getSlug(), false]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $seenByUser->ingredients),
        );
    }

    public function testListByDepartmentHidesTheMarkerFromAnAdministratorWhoIsNotTheCatalogOwner(): void
    {
        // Given : le propriétaire du catalogue existe ; un autre administrateur a un ingrédient marqué (inerte)
        $this->savedCatalogOwner();
        $admin = $this->savedUser(admin: true);
        $mine = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($admin, true)->withBase());

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $admin->getId());

        // Then : être administrateur ne montre pas le classement
        $this->assertSame([[$mine->getSlug(), false]], array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $result->ingredients));
    }

    public function testListByDepartmentNeverLooksForTheCatalogOwnerWhenNothingIsMarked(): void
    {
        // Given : le cas de tous les comptes aujourd'hui, aucun ingrédient marqué
        $user = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user, true));
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->ingredientLoader->listByDepartment('cereale', $user->getId());

        // Then : la liste du rayon, sans une requête de plus pour désigner le propriétaire
        $this->assertSame(2, $counter->count());
        $this->assertArrayNotHasKey('SELECT user', $counter->byTable());

        // Given : un ingrédient du lecteur marqué, donc une réponse qui dépend du propriétaire du catalogue
        $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user, true)->withBase());
        $counter->reset();

        // When
        $this->ingredientLoader->listByDepartment('cereale', $user->getId());

        // Then : une seule lecture du propriétaire, ce qui prouve que la mesure ci-dessus voit bien cette requête
        $this->assertSame(1, $counter->byTable()['SELECT user'] ?? 0);
    }

    public function testGetTellsTheCatalogOwnerHeCanMarkHisOwnIngredient(): void
    {
        // Given
        $owner = $this->savedCatalogOwner();
        $base = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($owner, true)->withBase());
        $notBase = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withCreator($owner, true));

        // When
        $baseResult = $this->ingredientLoader->get($base->getSlug(), $owner->getId());
        $notBaseResult = $this->ingredientLoader->get($notBase->getSlug(), $owner->getId());

        // Then
        $this->assertSame([true, true], [$baseResult->isBase, $baseResult->canSetBase]);
        $this->assertSame([false, true], [$notBaseResult->isBase, $notBaseResult->canSetBase]);
    }

    public function testGetDoesNotLetAnyoneButTheCatalogOwnerMarkAnything(): void
    {
        // Given : un utilisateur et ses ingrédients (l'un marqué, inerte), un administrateur qui n'est pas le propriétaire et son ingrédient marqué
        $this->savedCatalogOwner();
        $user = $this->savedUser();
        $admin = $this->savedUser(admin: true);
        $usersStray = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($user, true)->withBase());
        $usersOther = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withCreator($user, true));
        $adminsStray = $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withCreator($admin, true)->withBase());

        // When / Then : jamais vrai pour eux (le front s'appuie sur `canSetBase`, pas sur le rôle)
        foreach ([[$usersStray, $user], [$usersOther, $user], [$adminsStray, $admin]] as [$ingredient, $viewer]) {
            $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());
            $this->assertSame([false, false], [$result->isBase, $result->canSetBase], $ingredient->getSlug());
        }
    }

    public function testGetWithoutACatalogOwnerAccountMarksNothing(): void
    {
        // Given : le compte propriétaire n'existe pas (base neuve)
        $user = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($user, true)->withBase());

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $user->getId());

        // Then
        $this->assertSame([false, false], [$result->isBase, $result->canSetBase]);
    }

    public function testGetOfAnotherUsersIngredientIsStillUnknownToTheCatalogOwner(): void
    {
        // Given
        $owner = $this->savedCatalogOwner();
        $theirs = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($this->savedUser(), true));

        // Then : le marqueur et le rôle ne changent rien à la visibilité
        $this->expectExceptionObject(IngredientException::notFound($theirs->getSlug()));

        // When
        $this->ingredientLoader->get($theirs->getSlug(), $owner->getId());
    }
}

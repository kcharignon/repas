<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

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

    public function testListByDepartmentReturnsTheDepartmentAndTheVisibleIngredientsSortedByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('zzz riz')->withDepartment($department)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme()));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('aaa blé')->withDepartment($department)->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('mmm orge')->withDepartment($department)->withCreator($other, true));
        $this->savedIngredient(new IngredientBuilder()->withName('bbb autre rayon')->withDepartment(new DepartmentBuilder()->isBakery()));

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $viewer->getId(), false);

        // Then
        $this->assertEquals(new DepartmentDTO('cereale', 'céréale', new DepartmentBuilder()->isCereal()->build()->getImage()), $result->department);
        $this->assertEquals(
            [
                new IngredientRowDTO($own->getSlug(), 'aaa blé', '', true, false),
                new IngredientRowDTO($common->getSlug(), 'zzz riz', 'g', false, false),
            ],
            $result->ingredients,
        );
    }

    public function testListByDepartmentLetsAnAdministratorEditTheCommonIngredientsOnly(): void
    {
        // Given
        $admin = $this->savedUser();
        $other = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment($department));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment($department)->withCreator($admin, true));
        $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withDepartment($department)->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $admin->getId(), true);

        // Then
        $this->assertSame(
            [[$common->getSlug(), true], [$own->getSlug(), true]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->canEdit], $result->ingredients),
        );
    }

    public function testListByDepartmentReturnsAnEmptyListForADepartmentWithoutIngredient(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());

        // When
        $result = $this->ingredientLoader->listByDepartment('cereale', $viewer->getId(), false);

        // Then
        $this->assertSame([], $result->ingredients);
    }

    public function testListByDepartmentFailsForAnUnknownDepartment(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());

        // When
        $this->ingredientLoader->listByDepartment('inconnu', $this->savedUser()->getId(), false);
    }

    public function testGetReturnsTheIngredientWithItsDepartmentAndUnits(): void
    {
        // Given
        $viewer = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz farine')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($viewer, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme()));

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);

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
        ), $result);
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
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);

        // Then
        $this->assertSame([2, 2], [$result->recipeCount, $result->listCount]);
    }

    public function testGetCountsNothingForAViewerWhoDoesNotUseTheIngredient(): void
    {
        // Given : un commun que seul un autre utilisateur utilise
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('zzz riz'));
        $this->usage($common->getSlug(), $other->getId(), $other->getId());

        // When
        $result = $this->ingredientLoader->get($common->getSlug(), $viewer->getId(), false);

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
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);

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

        // When : seule la conversion commune existe
        $common = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(990)->build());
        $specific = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);

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
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);

        // Then
        $this->assertNull($result->coefficient);
    }

    public function testGetCannotEditACommonIngredientUnlessAdministrator(): void
    {
        // Given
        $viewer = $this->savedUser();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('zzz sel'));

        // When
        $asUser = $this->ingredientLoader->get($common->getSlug(), $viewer->getId(), false);
        $asAdmin = $this->ingredientLoader->get($common->getSlug(), $viewer->getId(), true);

        // Then
        $this->assertSame([false, null, true], [$asUser->canEdit, $asUser->creatorId, $asAdmin->canEdit]);
    }

    public function testGetHidesAnIngredientOfAnotherUserExceptToAnAdministrator(): void
    {
        // Given
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('zzz secret')->withCreator($owner, true));
        $viewer = $this->savedUser();

        // When
        $asAdmin = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), true);

        // Then
        $this->assertFalse($asAdmin->canEdit);
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));
        $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), false);
    }

    public function testGetFailsForAnUnknownIngredient(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->ingredientLoader->get('inconnu', $this->savedUser()->getId(), false);
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

    public function testListAvailableReturnsTheCommonAndOwnIngredientsSortedByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('mmm sel'));
        $own = $this->savedIngredient(new IngredientBuilder()->withName('aaa farine')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz secret')->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->listAvailable($viewer->getId());

        // Then
        $this->assertEquals(
            [new IngredientOptionDTO($own->getSlug(), 'aaa farine'), new IngredientOptionDTO($common->getSlug(), 'mmm sel')],
            $result,
        );
    }

    public function testListAvailableSearchesTheNameWithoutCaseNorAccentNorSpacing(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $cream = $this->savedIngredient(new IngredientBuilder()->withName('zzz Crème fraîche'));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz farine'));
        $ownCream = $this->savedIngredient(new IngredientBuilder()->withName('aaa crème maison')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('zzz crème secrète')->withCreator($other, true));

        // When
        $byAccentedText = $this->ingredientLoader->listAvailable($viewer->getId(), '  CRÈME ');
        $byPartOfTwoWords = $this->ingredientLoader->listAvailable($viewer->getId(), 'me fra');

        // Then : communs et propres, jamais ceux d'un autre, toujours triés par nom
        $this->assertEquals(
            [new IngredientOptionDTO($ownCream->getSlug(), 'aaa crème maison'), new IngredientOptionDTO($cream->getSlug(), 'zzz Crème fraîche')],
            $byAccentedText,
        );
        $this->assertEquals([new IngredientOptionDTO($cream->getSlug(), 'zzz Crème fraîche')], $byPartOfTwoWords);
    }

    public function testListAvailableWithABlankSearchKeepsEverything(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('zzz sel'));

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
        $exact = $this->savedIngredient(new IngredientBuilder()->withName('Riz')->withDepartment($cereal));
        $longer = $this->savedIngredient(new IngredientBuilder()->withName('Riz basmati')->withDepartment(new DepartmentBuilder()->isBakery()));
        $this->savedIngredient(new IngredientBuilder()->withName('Chorizo')->withDepartment(new DepartmentBuilder()->isMeat()));

        // When
        $result = $this->ingredientLoader->search('  RIZ ', $viewer->getId(), false);

        // Then
        $this->assertEquals(
            [
                new IngredientSearchResultDTO($exact->getSlug(), 'Riz', '', false, 'cereale', 'céréale', new DepartmentBuilder()->isCereal()->build()->getImage()),
                new IngredientSearchResultDTO($longer->getSlug(), 'Riz basmati', '', false, 'boulangerie', 'boulangerie', new DepartmentBuilder()->isBakery()->build()->getImage()),
            ],
            $result,
        );
    }

    public function testSearchForgivesATypoAndIgnoresAccents(): void
    {
        // Given
        $viewer = $this->savedUser();
        $cream = $this->savedIngredient(new IngredientBuilder()->withName('Crème fraîche'));
        $this->savedIngredient(new IngredientBuilder()->withName('Caramel'));

        // When
        $byTypo = $this->ingredientLoader->search('creme frache', $viewer->getId(), false);
        $byAccent = $this->ingredientLoader->search('CRÈME', $viewer->getId(), false);

        // Then
        $this->assertSame([$cream->getSlug()], array_map(static fn(IngredientSearchResultDTO $result): string => $result->slug, $byTypo));
        $this->assertSame([$cream->getSlug()], array_map(static fn(IngredientSearchResultDTO $result): string => $result->slug, $byAccent));
    }

    public function testSearchBreaksTiesByName(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Tomate séchée'));
        $this->savedIngredient(new IngredientBuilder()->withName('Tomate cerise'));

        // When
        $result = $this->ingredientLoader->search('tomate', $viewer->getId(), false);

        // Then : mêmes mots, même longueur de nom
        $this->assertSame(['Tomate cerise', 'Tomate séchée'], array_map(static fn(IngredientSearchResultDTO $result): string => $result->name, $result));
    }

    public function testSearchFindsTheCommonAndOwnIngredientsNeverThoseOfAnotherUser(): void
    {
        // Given
        $viewer = $this->savedUser();
        $other = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Sel'));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel fin')->withCreator($viewer, true));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel secret')->withCreator($other, true));

        // When
        $result = $this->ingredientLoader->search('sel', $viewer->getId(), false);

        // Then
        $this->assertSame(['Sel', 'Sel fin'], array_map(static fn(IngredientSearchResultDTO $result): string => $result->name, $result));
    }

    public function testSearchLetsAnAdministratorEditTheCommonIngredientsOnly(): void
    {
        // Given
        $admin = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Sel'));
        $this->savedIngredient(new IngredientBuilder()->withName('Sel fin')->withCreator($admin, true));

        // When
        $asAdmin = $this->ingredientLoader->search('sel', $admin->getId(), true);
        $asUser = $this->ingredientLoader->search('sel', $admin->getId(), false);

        // Then
        $this->assertSame([true, true], array_map(static fn(IngredientSearchResultDTO $result): bool => $result->canEdit, $asAdmin));
        $this->assertSame([false, true], array_map(static fn(IngredientSearchResultDTO $result): bool => $result->canEdit, $asUser));
    }

    public function testSearchKeepsTheThirtyMostRelevantResults(): void
    {
        // Given
        $viewer = $this->savedUser();
        for ($i = 0; $i < 35; $i++) {
            $this->savedIngredient(new IngredientBuilder()->withName('Fromage ' . $i));
        }

        // When
        $result = $this->ingredientLoader->search('fromage', $viewer->getId(), false);

        // Then
        $this->assertCount(30, $result);
    }

    public function testSearchFindsNothingForAnUnrelatedOrTextWithoutLetter(): void
    {
        // Given
        $viewer = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('Sel'));

        // When
        $unrelated = $this->ingredientLoader->search('zzzzzz', $viewer->getId(), false);
        $withoutLetter = $this->ingredientLoader->search(' !? ', $viewer->getId(), false);

        // Then
        $this->assertSame([], $unrelated);
        $this->assertSame([], $withoutLetter);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
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
        // Given : le propriétaire a un ingrédient de base et un autre qui ne l'est pas ; un ancien commun est marqué (le classement des 261
        // est posé avant la passation) ; un utilisateur ordinaire a aussi un ingrédient marqué (inerte : posé ailleurs que chez le propriétaire)
        $owner = $this->savedCatalogOwner();
        $user = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $ownersBase = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment($department)->withCreator($owner, true)->withBase());
        $ownersOther = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment($department)->withCreator($owner, true));
        $markedCommon = $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withDepartment($department)->withBase());
        $usersStray = $this->savedIngredient(new IngredientBuilder()->withName('ddd seigle')->withDepartment($department)->withCreator($user, true)->withBase());

        // When
        $seenByOwner = $this->ingredientLoader->listByDepartment('cereale', $owner->getId(), true);
        $seenByUser = $this->ingredientLoader->listByDepartment('cereale', $user->getId(), false);

        // Then : le propriétaire voit son classement, l'ancien commun marqué compris ; l'utilisateur ne voit aucun marqueur, pas même le sien
        $this->assertSame(
            [[$ownersBase->getSlug(), true], [$ownersOther->getSlug(), false], [$markedCommon->getSlug(), true]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $seenByOwner->ingredients),
        );
        $this->assertSame(
            [[$markedCommon->getSlug(), false], [$usersStray->getSlug(), false]],
            array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $seenByUser->ingredients),
        );
    }

    public function testListByDepartmentHidesTheMarkerFromAnAdministratorWhoIsNotTheCatalogOwner(): void
    {
        // Given
        $owner = $this->savedCatalogOwner();
        $admin = new UserBuilder()->isAdmin()->build();
        $this->persister->saveUser($admin);
        $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($owner, true)->withBase());
        $common = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment(new DepartmentBuilder()->isCereal())->withBase());

        // When : un administrateur voit les anciens communs (et ne voit pas les ingrédients du propriétaire)
        $result = $this->ingredientLoader->listByDepartment('cereale', $admin->getId(), true);

        // Then : être administrateur ne montre pas le classement
        $this->assertSame([[$common->getSlug(), false]], array_map(static fn(IngredientRowDTO $row): array => [$row->slug, $row->isBase], $result->ingredients));
    }

    public function testListByDepartmentNeverLooksForTheCatalogOwnerWhenNothingIsMarked(): void
    {
        // Given : le cas de tous les comptes aujourd'hui, aucun ingrédient marqué
        $user = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user, true));
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->ingredientLoader->listByDepartment('cereale', $user->getId(), false);

        // Then : la liste du rayon, sans une requête de plus pour désigner le propriétaire
        $this->assertSame(2, $counter->count());
        $this->assertArrayNotHasKey('SELECT user', $counter->byTable());

        // Given : un ancien commun marqué, donc une réponse qui dépend du propriétaire du catalogue
        $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withDepartment(new DepartmentBuilder()->isCereal())->withBase());
        $counter->reset();

        // When
        $this->ingredientLoader->listByDepartment('cereale', $user->getId(), false);

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
        $baseResult = $this->ingredientLoader->get($base->getSlug(), $owner->getId(), true);
        $notBaseResult = $this->ingredientLoader->get($notBase->getSlug(), $owner->getId(), true);

        // Then
        $this->assertSame([true, true], [$baseResult->isBase, $baseResult->canSetBase]);
        $this->assertSame([false, true], [$notBaseResult->isBase, $notBaseResult->canSetBase]);
    }

    public function testGetDoesNotLetAnyoneButTheCatalogOwnerMarkAnything(): void
    {
        // Given : un utilisateur et son ingrédient marqué (inerte), un administrateur qui n'est pas le propriétaire et un ancien commun marqué
        $this->savedCatalogOwner();
        $user = $this->savedUser();
        $admin = new UserBuilder()->isAdmin()->build();
        $this->persister->saveUser($admin);
        $usersStray = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($user, true)->withBase());
        $usersOther = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withCreator($user, true));
        $markedCommon = $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withBase());

        // When / Then : jamais vrai pour eux (le front s'appuie sur `canSetBase`, pas sur le rôle)
        foreach ([
            [$usersStray, $user, false],
            [$usersOther, $user, false],
            [$markedCommon, $user, false],
            [$markedCommon, $admin, true],
            [$usersStray, $admin, true],
        ] as [$ingredient, $viewer, $viewerIsAdmin]) {
            $result = $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId(), $viewerIsAdmin);
            $this->assertSame([false, false], [$result->isBase, $result->canSetBase], $ingredient->getSlug());
        }
    }

    public function testGetShowsTheOwnerTheMarkOfAnOldCommonButDoesNotLetHimSetItThere(): void
    {
        // Given : avant la passation, les anciens communs (sans créateur) sont les ingrédients du catalogue, ils ne sont pas encore à lui
        $owner = $this->savedCatalogOwner();
        $common = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withBase());
        $another = $this->savedUser();
        $anothers = $this->savedIngredient(new IngredientBuilder()->withName('bbb blé')->withCreator($another, true)->withBase());

        // When : l'ingrédient d'un autre est lisible par un administrateur (passe-droit), propriétaire du catalogue compris
        $commonResult = $this->ingredientLoader->get($common->getSlug(), $owner->getId(), true);
        $anothersResult = $this->ingredientLoader->get($anothers->getSlug(), $owner->getId(), true);

        // Then
        $this->assertSame([true, false], [$commonResult->isBase, $commonResult->canSetBase]);
        $this->assertSame([false, false], [$anothersResult->isBase, $anothersResult->canSetBase]);
    }

    public function testGetWithoutACatalogOwnerAccountMarksNothing(): void
    {
        // Given : le compte propriétaire n'existe pas (base neuve)
        $user = $this->savedUser();
        $ingredient = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($user, true)->withBase());

        // When
        $result = $this->ingredientLoader->get($ingredient->getSlug(), $user->getId(), false);

        // Then
        $this->assertSame([false, false], [$result->isBase, $result->canSetBase]);
    }

    public function testGetOfAnotherUsersIngredientIsStillUnknown(): void
    {
        // Given
        $owner = $this->savedCatalogOwner();
        $theirs = $this->savedIngredient(new IngredientBuilder()->withName('aaa riz')->withCreator($this->savedUser(), true));

        // Then : le marqueur ne change rien à la visibilité
        $this->expectExceptionObject(IngredientException::notFound($theirs->getSlug()));

        // When
        $this->ingredientLoader->get($theirs->getSlug(), $owner->getId(), false);
    }
}

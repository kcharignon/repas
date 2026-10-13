<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
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
                new IngredientRowDTO($own->getSlug(), 'aaa blé', '', true),
                new IngredientRowDTO($common->getSlug(), 'zzz riz', 'g', false),
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
        ), $result);
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

    public function testListAvailableIsEmptyWithoutIngredient(): void
    {
        // When
        $result = $this->ingredientLoader->listAvailable($this->savedUser()->getId());

        // Then
        $this->assertSame([], $result);
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
}

<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\RepasAssert;

class IngredientTest extends TestCase
{
    public function testAdoptingACommonMakesAPersonalCopyThatRemembersWhereItComesFrom(): void
    {
        // Given
        $common = new IngredientBuilder()->isButter()->build();

        // When
        $adoption = Ingredient::adopt($common, 'user-id', 'beurreuser-id');

        // Then : mêmes nom, image, rayon, unités et unités compatibles ; créateur et origine posés
        $this->assertSame('beurreuser-id', $adoption->getSlug());
        $this->assertSame('user-id', $adoption->getCreatorId());
        $this->assertSame('beurre', $adoption->getAdoptedFrom());
        $this->assertTrue($adoption->isAdoption());
        $this->assertSame([$common->getName(), $common->getSluggedName(), $common->getImage()], [$adoption->getName(), $adoption->getSluggedName(), $adoption->getImage()]);
        RepasAssert::assertDepartment($common->getDepartment(), $adoption->getDepartment());
        RepasAssert::assertUnit($common->getDefaultCookingUnit(), $adoption->getDefaultCookingUnit());
        RepasAssert::assertUnit($common->getDefaultPurchaseUnit(), $adoption->getDefaultPurchaseUnit());
        $this->assertSame(
            array_map(static fn($unit) => $unit->getSlug(), array_values($common->getCompatibleUnits()->toArray())),
            array_map(static fn($unit) => $unit->getSlug(), array_values($adoption->getCompatibleUnits()->toArray())),
        );
    }

    public function testAdoptingLeavesTheCommonUntouchedAndDoesNotShareItsUnitsList(): void
    {
        // Given
        $common = new IngredientBuilder()->isButter()->build();
        $before = $common->toArray();

        // When
        $adoption = Ingredient::adopt($common, 'user-id', 'beurreuser-id');
        $adoption->setCompatibleUnits($adoption->getCompatibleUnits()->slice(0, 1));
        $adoption->setName('Beurre doux');

        // Then
        $this->assertSame($before, $common->toArray());
        $this->assertNull($common->getCreatorId());
        $this->assertFalse($common->isAdoption());
        $this->assertCount(3, $common->getCompatibleUnits());
    }

    public function testRenamingAnAdoptionKeepsItsOriginAndItsSlug(): void
    {
        // Given
        $adoption = Ingredient::adopt(new IngredientBuilder()->isButter()->build(), 'user-id', 'beurreuser-id');

        // When
        $adoption->update(
            'Beurre demi-sel',
            $adoption->getImage(),
            $adoption->getDepartment(),
            $adoption->getDefaultCookingUnit(),
            $adoption->getDefaultPurchaseUnit(),
            $adoption->getCompatibleUnits(),
        );

        // Then : un fait immuable, quoi que devienne l'ingrédient
        $this->assertSame('beurre', $adoption->getAdoptedFrom());
        $this->assertSame('beurreuser-id', $adoption->getSlug());
    }

    public function testAnIngredientCreatedByAUserOrCommonIsNotAnAdoption(): void
    {
        // Given
        $personal = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('user-id')->build(), true)->build();
        $common = new IngredientBuilder()->isButter()->build();

        // Then
        $this->assertNull($personal->getAdoptedFrom());
        $this->assertFalse($personal->isAdoption());
        $this->assertFalse($common->isAdoption());
    }

    public function testACopyMadeWithARecipeOfAPersonalIngredientIsNotAnAdoptionAndTakesTheGivenSlug(): void
    {
        // Given
        $original = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'copier-id', 'beurre-2copier-id');

        // Then : le slug est celui que l'appelant a choisi libre, le reste est celui de l'original
        $this->assertSame('beurre-2copier-id', $copy->getSlug());
        $this->assertSame('copier-id', $copy->getCreatorId());
        $this->assertFalse($copy->isAdoption());
        $this->assertNull($copy->getAdoptedFrom());
        $this->assertSame($original->getName(), $copy->getName());
        $this->assertSame($original->getImage(), $copy->getImage());
        $this->assertSame($original->getSluggedName(), $copy->getSluggedName());
        $this->assertTrue($original->getDepartment()->isEqual($copy->getDepartment()));
        $this->assertTrue($original->getDefaultPurchaseUnit()->isEqual($copy->getDefaultPurchaseUnit()));
        $this->assertTrue($original->getDefaultCookingUnit()->isEqual($copy->getDefaultCookingUnit()));
        $this->assertEquals($original->getCompatibleUnits()->toArray(), $copy->getCompatibleUnits()->toArray());
        $this->assertSame('author-id', $original->getCreatorId(), 'L\'original n\'est pas touché.');
    }

    public function testTheCopyOfAnAdoptionKeepsTheCommonItComesFromAndIsStillNotACreation(): void
    {
        // Given : l'adoption d'un commun par l'auteur
        $common = new IngredientBuilder()->isButter()->build();
        $adoption = Ingredient::adopt($common, 'author-id', 'beurreauthor-id');

        // When
        $copy = Ingredient::copyFromOriginal($adoption, 'copier-id', 'beurrecopier-id');

        // Then : la provenance voyage
        $this->assertSame('beurre', $copy->getAdoptedFrom());
        $this->assertTrue($copy->isAdoption());
    }

    public function testTheCopyOfACommonRetainsTheCommonAsItsOrigin(): void
    {
        // Given : un commun que le copieur ne peut pas utiliser tel quel
        $common = new IngredientBuilder()->isButter()->build();

        // When
        $copy = Ingredient::copyFromOriginal($common, 'copier-id', 'beurre-2copier-id');

        // Then
        $this->assertSame('beurre', $copy->getAdoptedFrom());
        $this->assertSame('copier-id', $copy->getCreatorId());
    }

    public function testTheCopyDoesNotShareItsCompatibleUnitsWithTheOriginal(): void
    {
        // Given
        $original = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->build();
        $copy = Ingredient::copyFromOriginal($original, 'copier-id', 'beurrecopier-id');

        // When
        $copy->setCompatibleUnits(Tab::newEmptyTyped(Unit::class));

        // Then
        $this->assertCount(3, $original->getCompatibleUnits());
    }

    public function testACommonIngredientIsOwnedByNobody(): void
    {
        // Given
        $common = new IngredientBuilder()->isButter()->build();

        // Then : un commun n'est à personne, pas même à l'utilisateur qui l'a adopté
        $this->assertTrue($common->isCommon());
        $this->assertFalse($common->isOwnedBy('user-id'));
        $this->expectExceptionObject(IngredientException::notFound('beurre'));
        $common->assertOwnedBy('user-id');
    }

    public function testAnIngredientIsOwnedByItsCreatorOnly(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('user-id')->build(), true)->build();

        // Then
        $this->assertFalse($ingredient->isCommon());
        $this->assertTrue($ingredient->isOwnedBy('user-id'));
        $ingredient->assertOwnedBy('user-id');
        $this->assertFalse($ingredient->isOwnedBy('another-id'));
    }

    public function testTheIngredientOfAnotherUserIsUnknownLikeAnythingElse(): void
    {
        // Given : un administrateur n'y change rien, l'ingrédient est au moins l'ingrédient de quelqu'un d'autre
        $ingredient = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('user-id')->build(), true)->build();

        // Then
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));
        $ingredient->assertOwnedBy('another-id');
    }

    public function testAnIngredientIsNotBaseUnlessMarked(): void
    {
        // Given
        $created = Ingredient::create('Praliné', '', new DepartmentBuilder()->isBakery()->build(), new UnitBuilder()->isGramme()->build(), new UnitBuilder()->isGramme()->build(), 'user-id');
        $loadedWithoutTheKey = Ingredient::load([
            'slug' => 'sel', 'name' => 'sel', 'image' => '', 'slugged_name' => 'sel',
            'department' => new DepartmentBuilder()->isBakery()->build(), 'default_cooking_unit' => new UnitBuilder()->isGramme()->build(),
            'default_purchase_unit' => new UnitBuilder()->isGramme()->build(), 'creator_id' => null, 'compatible_units' => Tab::newEmptyTyped(Unit::class),
            'adopted_from' => null,
        ]);

        // Then : une création est à faux, un chargement sans la clé aussi (le code d'avant la colonne), un chargement avec la clé la lit
        $this->assertFalse($created->isBase());
        $this->assertFalse($loadedWithoutTheKey->isBase());
        $this->assertTrue(new IngredientBuilder()->isButter()->withBase()->build()->isBase());
        $this->assertFalse(new IngredientBuilder()->isButter()->withBase()->withBase(false)->build()->isBase());
    }

    public function testChangingTheBaseMarkerIsFluentAndReversible(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->build();

        // When / Then
        $this->assertSame($ingredient, $ingredient->changeBase(true));
        $this->assertTrue($ingredient->isBase());
        $this->assertFalse($ingredient->changeBase(false)->isBase());
    }

    public function testUpdatingAnIngredientKeepsItsBaseMarker(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->withBase()->build();

        // When
        $ingredient->update('Beurre demi-sel', $ingredient->getImage(), $ingredient->getDepartment(), $ingredient->getDefaultCookingUnit(), $ingredient->getDefaultPurchaseUnit(), $ingredient->getCompatibleUnits());

        // Then
        $this->assertTrue($ingredient->isBase());
    }

    public function testEveryCopyIsBornNotBaseWhateverItsSource(): void
    {
        // Given : le marqueur ne voyage pas, ni par une adoption, ni par une copie de recette, d'un commun comme d'un ingrédient à quelqu'un
        $baseCommon = new IngredientBuilder()->isButter()->withBase()->build();
        $basePersonal = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->withBase()->build();

        // When
        $copies = [
            Ingredient::adopt($baseCommon, 'user-id', 'beurreuser-id'),
            Ingredient::adopt($basePersonal, 'user-id', 'beurreuser-id'),
            Ingredient::copyFromOriginal($baseCommon, 'user-id', 'beurreuser-id'),
            Ingredient::copyFromOriginal($basePersonal, 'user-id', 'beurreuser-id'),
        ];

        // Then : et la source garde le sien
        foreach ($copies as $copy) {
            $this->assertFalse($copy->isBase());
        }
        $this->assertTrue($baseCommon->isBase());
        $this->assertTrue($basePersonal->isBase());
    }

    /**
     * La règle « qui pose le marqueur » : le propriétaire du catalogue, sur un ingrédient à lui (ou, jusqu'à ING-014, sans créateur) ; personne
     * quand le propriétaire n'existe pas.
     *
     * @return iterable<string, array{string, ?string, ?string, bool}>
     */
    public static function baseMarkerCases(): iterable
    {
        // demandeur, propriétaire du catalogue, créateur de l'ingrédient, permis ?
        yield 'le propriétaire sur son ingrédient' => ['owner-id', 'owner-id', 'owner-id', true];
        yield 'le propriétaire sur un ancien commun' => ['owner-id', 'owner-id', null, true];
        yield "le propriétaire sur l'ingrédient d'un autre" => ['owner-id', 'owner-id', 'user-id', false];
        yield 'un utilisateur sur son ingrédient' => ['user-id', 'owner-id', 'user-id', false];
        yield 'un utilisateur sur un ancien commun' => ['user-id', 'owner-id', null, false];
        yield "un utilisateur sur l'ingrédient du propriétaire" => ['user-id', 'owner-id', 'owner-id', false];
        yield "un administrateur qui n'est pas le propriétaire, sur un ancien commun" => ['admin-id', 'owner-id', null, false];
        yield "le propriétaire n'existe pas, personne ne peut" => ['owner-id', null, 'owner-id', false];
        yield "le propriétaire n'existe pas, pas même sur un ancien commun" => ['user-id', null, null, false];
    }

    #[DataProvider('baseMarkerCases')]
    public function testOnlyTheCatalogOwnerSetsTheBaseMarkerOnItsOwnIngredient(string $userId, ?string $catalogOwnerId, ?string $creatorId, bool $allowed): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->withCreator($creatorId === null ? null : new UserBuilder()->withId($creatorId)->build(), true)->build();

        // When / Then
        if (!$allowed) {
            $this->expectExceptionObject(IngredientException::baseMarkerForbidden());
        }
        $ingredient->assertBaseMarkerSettableBy($userId, $catalogOwnerId);
        $this->assertTrue($allowed);
    }

    public function testTheRefusalIsAForbiddenWithAStableCode(): void
    {
        // When
        $exception = IngredientException::baseMarkerForbidden();

        // Then : le front distingue ce refus d'un refus d'accès ordinaire par ce code, qui ne se renomme pas
        $this->assertSame('INGREDIENT_BASE_FORBIDDEN', $exception->getMessage());
        $this->assertSame(403, $exception->getCode());
    }
}

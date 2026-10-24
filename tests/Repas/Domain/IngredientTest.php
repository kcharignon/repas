<?php

namespace Repas\Tests\Repas\Domain;


use LogicException;
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
    public function testRenamingAnAdoptionKeepsItsOriginAndItsSlug(): void
    {
        // Given
        $adoption = Ingredient::copyFromOriginal(new IngredientBuilder()->isButter()->build(), 'user-id', 'beurreuser-id');

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

    public function testACopyMadeWithARecipeTakesTheGivenSlugAndRetainsItsOrigin(): void
    {
        // Given
        $original = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'copier-id', 'beurre-2copier-id');

        // Then : le slug est celui que l'appelant a choisi libre, le reste est celui de l'original
        $this->assertSame('beurre-2copier-id', $copy->getSlug());
        $this->assertSame('copier-id', $copy->getCreatorId());
        // D7 : toute copie retient son origine (ici l'original n'en a pas : son slug), elle n'est donc jamais une création
        $this->assertTrue($copy->isAdoption());
        $this->assertSame($original->getSlug(), $copy->getAdoptedFrom());
        $this->assertSame($original->getName(), $copy->getName());
        $this->assertSame($original->getImage(), $copy->getImage());
        $this->assertSame($original->getSluggedName(), $copy->getSluggedName());
        $this->assertTrue($original->getDepartment()->isEqual($copy->getDepartment()));
        $this->assertTrue($original->getDefaultPurchaseUnit()->isEqual($copy->getDefaultPurchaseUnit()));
        $this->assertTrue($original->getDefaultCookingUnit()->isEqual($copy->getDefaultCookingUnit()));
        $this->assertEquals($original->getCompatibleUnits()->toArray(), $copy->getCompatibleUnits()->toArray());
        $this->assertSame('author-id', $original->getCreatorId(), 'L\'original n\'est pas touché.');
    }

    public function testTheCopyOfACopyKeepsTheOriginItComesFromAndIsStillNotACreation(): void
    {
        // Given : la copie, par l'auteur, d'un ingrédient du catalogue
        $common = new IngredientBuilder()->isButter()->build();
        $adoption = Ingredient::copyFromOriginal($common, 'author-id', 'beurreauthor-id');

        // When
        $copy = Ingredient::copyFromOriginal($adoption, 'copier-id', 'beurrecopier-id');

        // Then : la provenance voyage
        $this->assertSame('beurre', $copy->getAdoptedFrom());
        $this->assertTrue($copy->isAdoption());
    }

    public function testTheCopyOfAnIngredientWithoutOriginRetainsItsSlugAsItsOrigin(): void
    {
        // Given : un ingrédient du propriétaire du catalogue (un ancien commun), sans origine
        $original = new IngredientBuilder()->isButter()->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'copier-id', 'beurre-2copier-id');

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

    public function testAddingCompatibleUnitsKeepsTheExistingOnesAndNeverDuplicates(): void
    {
        // Given : du beurre, compatible en gramme, kilo et bloc
        $butter = new IngredientBuilder()->isButter()->build();
        $before = array_map(static fn(Unit $unit): string => $unit->getSlug(), $butter->getCompatibleUnits()->toArray());

        // When : on ajoute la tranche, puis le gramme (déjà compatible) et la tranche encore
        $result = $butter->addCompatibleUnits([new UnitBuilder()->isSlice()->build(), new UnitBuilder()->isGramme()->build(), new UnitBuilder()->isSlice()->build()]);

        // Then : fluent, les anciennes unités dans le même ordre, la tranche une seule fois à la fin
        $this->assertSame($butter, $result);
        $this->assertSame([...$before, 'tranche'], array_map(static fn(Unit $unit): string => $unit->getSlug(), $butter->getCompatibleUnits()->toArray()));
    }

    public function testAnIngredientWithoutAnOwnerIsRefusedAtLoadWithAClearMessage(): void
    {
        // Given : la forme de la base d'avant la passation, que ce code ne lit plus
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("n'a pas de créateur");

        // When
        new IngredientBuilder()->isButter()->withoutCreator()->build();
    }

    public function testAnIngredientIsOwnedByItsCreatorOnly(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('user-id')->build(), true)->build();

        // Then
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
            'default_purchase_unit' => new UnitBuilder()->isGramme()->build(), 'creator_id' => 'user-id', 'compatible_units' => Tab::newEmptyTyped(Unit::class),
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
        // Given : le marqueur ne voyage pas, ni par une copie à l'activation, ni par une copie de recette, d'un ingrédient du catalogue comme de celui de quelqu'un
        $baseCommon = new IngredientBuilder()->isButter()->withBase()->build();
        $basePersonal = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->withBase()->build();

        // When
        $copies = [
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
        yield 'le propriétaire sur un ingrédient sans propriétaire (plus traité à part)' => ['owner-id', 'owner-id', null, false];
        yield "le propriétaire sur l'ingrédient d'un autre" => ['owner-id', 'owner-id', 'user-id', false];
        yield 'un utilisateur sur son ingrédient' => ['user-id', 'owner-id', 'user-id', false];
        yield "un utilisateur sur l'ingrédient du propriétaire" => ['user-id', 'owner-id', 'owner-id', false];
        yield "un administrateur qui n'est pas le propriétaire, sur son ingrédient" => ['admin-id', 'owner-id', 'admin-id', false];
        yield "le propriétaire n'existe pas, personne ne peut" => ['owner-id', null, 'owner-id', false];
        yield "le propriétaire n'existe pas, pas même sur l'ingrédient de l'utilisateur" => ['user-id', null, 'user-id', false];
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

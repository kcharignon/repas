<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
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
}

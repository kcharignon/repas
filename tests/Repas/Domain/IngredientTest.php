<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Ingredient;
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

    public function testACopyMadeWithARecipeIsNotAnAdoption(): void
    {
        // Given : le motif existant de copie de recette ne retient pas d'origine (ING-004 y reviendra)
        $original = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('author-id')->build(), true)->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'copier-id');

        // Then
        $this->assertFalse($copy->isAdoption());
    }
}

<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * Une PARTIE d'un ingrédient (ADR 0038, SUB-001) : « Jaune d'œuf » est une partie d'« Œuf », au ratio 1. Le modèle juge ce qu'il sait seul
 * (pas lui-même, un ratio fini de 0,000001 à 1 000 000, le parent et le ratio posés ensemble) ; le reste (parent à l'utilisateur, pas de
 * chaîne, plafond de dix, listes en cours) est jugé par les cas d'usage.
 */
class IngredientPartTest extends TestCase
{
    public function testAnOrdinaryIngredientHasNoParentAndIsNotAPart(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isEgg()->build();

        // Then
        $this->assertFalse($ingredient->isPart());
        $this->assertNull($ingredient->getParentSlug());
        $this->assertNull($ingredient->getParentRatio());
    }

    public function testAParentHasTenPartsAtMost(): void
    {
        // Then : ADR 0038 décision 2 (le plafond est jugé par le cas d'usage, qui lit cette constante)
        $this->assertSame(10, (new \ReflectionClassConstant(Ingredient::class, 'MAX_PARTS'))->getValue());
    }

    public function testAnIngredientBecomesAPartOfAnotherWithItsRatio(): void
    {
        // Given
        $yolk = new IngredientBuilder()->withName("Jaune d'œuf")->build();

        // When
        $returned = $yolk->makePartOf('oeuf', 1.0);

        // Then : le parent et le ratio sont posés ensemble, la méthode est fluide
        $this->assertSame($yolk, $returned);
        $this->assertTrue($yolk->isPart());
        $this->assertSame('oeuf', $yolk->getParentSlug());
        $this->assertSame(1.0, $yolk->getParentRatio());
    }

    public function testThePartChangesOfParentAndOfRatio(): void
    {
        // Given
        $half = new IngredientBuilder()->withName('Demi-gousse')->build()->makePartOf('gousse', 2.0);

        // When
        $half->makePartOf('gousse', 4.0);
        $other = new IngredientBuilder()->withName('Demi-gousse')->withParent('gousse', 2.0)->build()->makePartOf('vanille', 0.5);

        // Then
        $this->assertSame(['gousse', 4.0], [$half->getParentSlug(), $half->getParentRatio()]);
        $this->assertSame(['vanille', 0.5], [$other->getParentSlug(), $other->getParentRatio()]);
    }

    public function testDetachingGivesBackAnOrdinaryIngredientWithNeitherParentNorRatio(): void
    {
        // Given
        $yolk = new IngredientBuilder()->withName("Jaune d'œuf")->withParent('oeuf', 1.0)->build();
        $this->assertTrue($yolk->isPart());

        // When
        $returned = $yolk->detachFromParent();

        // Then : les deux ensemble ; recoller un ingrédient ordinaire ne change rien
        $this->assertSame($yolk, $returned);
        $this->assertFalse($yolk->isPart());
        $this->assertNull($yolk->getParentSlug());
        $this->assertNull($yolk->getParentRatio());
        $this->assertNull($yolk->detachFromParent()->getParentSlug());
    }

    public function testAnIngredientIsNotItsOwnPart(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->build();

        // Then
        $this->expectExceptionObject(IngredientException::partOfItself());

        // When
        $egg->makePartOf($egg->getSlug(), 1.0);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRatios(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'négatif' => [-1.0];
        yield 'moins que un millionième' => [0.0000001];
        yield 'plus que un million' => [1_000_001.0];
        yield 'non fini' => [INF];
        yield 'pas un nombre' => [NAN];
    }

    #[DataProvider('invalidRatios')]
    public function testARatioOutsideTheBoundsIsRefusedAndNothingIsWritten(float $ratio): void
    {
        // Given : une partie déjà reliée
        $yolk = new IngredientBuilder()->withName("Jaune d'œuf")->withParent('oeuf', 1.0)->build();

        // When / Then
        try {
            $yolk->makePartOf('autre', $ratio);
            $this->fail('Le ratio aurait dû être refusé.');
        } catch (IngredientException $exception) {
            $this->assertSame(IngredientException::PART_RATIO_INVALID, $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
        $this->assertSame(['oeuf', 1.0], [$yolk->getParentSlug(), $yolk->getParentRatio()]);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        // Given
        $part = new IngredientBuilder()->withName('Pincée')->build();

        // When / Then
        $this->assertSame(0.000001, $part->makePartOf('sel', 0.000001)->getParentRatio());
        $this->assertSame(1_000_000.0, $part->makePartOf('sel', 1_000_000.0)->getParentRatio());
    }

    public function testTheFiveErrorsHaveTheirOwnMessageAndStatus(): void
    {
        // Then : le front les distingue par leur message, qui ne se renomme pas (ADR 0038 décision 29)
        $this->assertSame(['INGREDIENT_PART_OF_ITSELF', 422], [IngredientException::partOfItself()->getMessage(), IngredientException::partOfItself()->getCode()]);
        $this->assertSame(['INGREDIENT_PART_CHAIN', 422], [IngredientException::partChain()->getMessage(), IngredientException::partChain()->getCode()]);
        $this->assertSame(['INGREDIENT_PART_LIMIT_REACHED', 422], [IngredientException::partLimitReached()->getMessage(), IngredientException::partLimitReached()->getCode()]);
        $this->assertSame(['INGREDIENT_PART_RATIO_INVALID', 422], [IngredientException::partRatioInvalid()->getMessage(), IngredientException::partRatioInvalid()->getCode()]);
        $this->assertSame(['INGREDIENT_PART_IN_LIST', 409], [IngredientException::partInOpenList()->getMessage(), IngredientException::partInOpenList()->getCode()]);
    }

    public function testACopyNeverCarriesTheParentOfItsOriginal(): void
    {
        // Given : une partie d'un autre compte
        $original = new IngredientBuilder()->withName("Jaune d'œuf")->withCreator(null)->withParent('oeuf-autre-compte', 1.0)->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'copier-id', 'jaune-d-oeufcopier-id');

        // Then : le slug du parent est celui d'un ingrédient d'un autre compte, jamais recopié (ADR 0038 décision 5)
        $this->assertFalse($copy->isPart());
        $this->assertNull($copy->getParentSlug());
        $this->assertNull($copy->getParentRatio());
        $this->assertTrue($original->isPart());
    }

    public function testACreatedIngredientIsOrdinary(): void
    {
        // Given / When
        $created = Ingredient::create("Jaune d'œuf", '', new DepartmentBuilder()->isMiscellaneous()->build(), new UnitBuilder()->isUnite()->build(), new UnitBuilder()->isUnite()->build(), 'user-id');

        // Then
        $this->assertFalse($created->isPart());
        $this->assertNull($created->getParentSlug());
        $this->assertNull($created->getParentRatio());
    }

    public function testUpdatingAnIngredientKeepsItsParent(): void
    {
        // Given
        $yolk = new IngredientBuilder()->withName("Jaune d'œuf")->withParent('oeuf', 2.0)->build();

        // When
        $yolk->update("Jaune d'œuf frais", $yolk->getImage(), $yolk->getDepartment(), $yolk->getDefaultCookingUnit(), $yolk->getDefaultPurchaseUnit(), $yolk->getCompatibleUnits());

        // Then
        $this->assertSame(['oeuf', 2.0], [$yolk->getParentSlug(), $yolk->getParentRatio()]);
    }

    public function testLoadingWithoutTheKeysGivesAnOrdinaryIngredient(): void
    {
        // Given : le code d'avant les colonnes ne les connaît pas
        $loaded = Ingredient::load([
            'slug' => 'sel', 'name' => 'sel', 'image' => '', 'slugged_name' => 'sel',
            'department' => new DepartmentBuilder()->isBakery()->build(), 'default_cooking_unit' => new UnitBuilder()->isGramme()->build(),
            'default_purchase_unit' => new UnitBuilder()->isGramme()->build(), 'creator_id' => 'user-id',
            'compatible_units' => new IngredientBuilder()->build()->getCompatibleUnits(), 'adopted_from' => null,
        ]);

        // Then
        $this->assertFalse($loaded->isPart());
    }
}

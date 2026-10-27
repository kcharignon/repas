<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * Les bornes d'une recette tiennent dans le modèle de domaine, dernière garde : une quantité finie et plausible, un nombre de personnes de
 * 1 à 100, au plus 100 lignes. Ce qui est déjà enregistré se lit et se copie sans être jugé.
 */
class RecipeBoundsTest extends TestCase
{
    private const string AUTHOR = 'author-id';

    private function author(): UserBuilder
    {
        return new UserBuilder()->withId(self::AUTHOR);
    }

    private function ingredient(): \Repas\Repas\Domain\Model\Ingredient
    {
        return new IngredientBuilder()->isPasta()->withCreator($this->author()->build(), true)->build();
    }

    private function row(float $quantity): RecipeRow
    {
        return RecipeRow::create('row-id', 'recipe-id', $this->ingredient(), $quantity, new UnitBuilder()->isGramme()->build());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'négative' => [-1.0];
        yield 'infinie' => [INF];
        yield 'indéfinie' => [NAN];
        yield 'démesurée' => [1_000_001.0];
    }

    #[DataProvider('invalidQuantities')]
    public function testARowCannotBeBuiltWithAnInvalidQuantity(float $quantity): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::invalidQuantity());

        // When
        $this->row($quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function testARowQuantityCannotBeChangedToAnInvalidOne(float $quantity): void
    {
        // Given
        $row = $this->row(100.0);

        // Then
        $this->expectExceptionObject(RecipeException::invalidQuantity());

        // When
        $row->setQuantity($quantity);
    }

    public function testARowAcceptsTheLimitsOfTheRange(): void
    {
        // When / Then
        $this->assertSame(1_000_000.0, $this->row(1_000_000.0)->getQuantity());
        $this->assertSame(0.001, $this->row(0.001)->getQuantity());
    }

    public function testScalingARowToANonFiniteQuantityIsRefused(): void
    {
        // Given : une quantité déjà enregistrée, plus grande que les bornes, mise à l'échelle
        $row = RecipeRow::load(['id' => 'r', 'recipe_id' => 'x', 'ingredient' => $this->ingredient(), 'quantity' => PHP_FLOAT_MAX, 'unit' => new UnitBuilder()->isGramme()->build()]);

        // Then
        $this->expectExceptionObject(RecipeException::invalidQuantity());

        // When
        $row->multiplyQuantityBy(2.0);
    }

    public function testScalingARowAboveTheMaximumIsAllowedWhileItStaysFinite(): void
    {
        // Given : un million pour une personne, planifié pour cent
        $row = $this->row(1_000_000.0);

        // When
        $row->multiplyQuantityBy(100.0);

        // Then
        $this->assertSame(100_000_000.0, $row->getQuantity());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidServings(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-3];
        yield 'cent un' => [101];
    }

    #[DataProvider('invalidServings')]
    public function testARecipeCannotBeCreatedForAnInvalidNumberOfPeople(int $serving): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::invalidServing());

        // When
        $this->create($serving, Tab::newEmptyTyped(RecipeRow::class));
    }

    #[DataProvider('invalidServings')]
    public function testARecipeCannotBeChangedToAnInvalidNumberOfPeople(int $serving): void
    {
        // Given
        $recipe = $this->create(4, Tab::newEmptyTyped(RecipeRow::class));

        // Then
        $this->expectExceptionObject(RecipeException::invalidServing());

        // When
        $recipe->update('Autre', new RecipeTypeBuilder()->isMeal()->build(), $serving);
    }

    public function testARecipeAcceptsOneAndAHundredPeople(): void
    {
        // When / Then
        $this->assertSame(1, $this->create(1, Tab::newEmptyTyped(RecipeRow::class))->getServing());
        $this->assertSame(100, $this->create(100, Tab::newEmptyTyped(RecipeRow::class))->getServing());
    }

    public function testARecipeKeepsUpToAHundredRows(): void
    {
        // When
        $recipe = $this->create(4, $this->rows(Recipe::MAX_ROWS));

        // Then
        $this->assertSame(Recipe::MAX_ROWS, $recipe->getRows()->count());
    }

    public function testARecipeCannotBeCreatedWithMoreThanAHundredRows(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::tooManyRows());

        // When
        $this->create(4, $this->rows(Recipe::MAX_ROWS + 1));
    }

    public function testARowCannotBeAddedToAFullRecipe(): void
    {
        // Given
        $recipe = $this->create(4, $this->rows(Recipe::MAX_ROWS));

        // Then
        $this->expectExceptionObject(RecipeException::tooManyRows());

        // When
        $recipe->addRow($this->ingredient(), new UnitBuilder()->isGramme()->build(), 10.0);
    }

    public function testTheRowsCannotBeReplacedByMoreThanAHundred(): void
    {
        // Given
        $recipe = $this->create(4, Tab::newEmptyTyped(RecipeRow::class));

        // Then
        $this->expectExceptionObject(RecipeException::tooManyRows());

        // When
        $recipe->setRows($this->rows(Recipe::MAX_ROWS + 1));
    }

    public function testARecipeAlreadyStoredWithMoreRowsIsCopiedAndReadAsItIs(): void
    {
        // Given : une recette plus ancienne que la borne (cent vingt lignes, une quantité démesurée)
        $ingredient = $this->ingredient();
        $original = Recipe::load([
            'id' => 'original',
            'name' => 'Ancienne',
            'serving' => 450,
            'author_id' => self::AUTHOR,
            'type' => new RecipeTypeBuilder()->isMeal()->build(),
            'rows' => $this->rows(120)->map(fn(RecipeRow $row) => RecipeRow::load(['id' => $row->getId(), 'recipe_id' => 'original', 'ingredient' => $ingredient, 'quantity' => 5_000_000.0, 'unit' => $row->getUnit()])),
            'original_id' => null,
        ]);

        // When : la copie, faite par le même auteur
        $copy = Recipe::copyFromOriginal('copy', $original, self::AUTHOR, [$ingredient->getSlug() => $ingredient], static fn(): string => uniqid('row-', true));

        // Then : elle ne refuse rien
        $this->assertSame(120, $copy->getRows()->count());
        $this->assertSame(450, $copy->getServing());
        $this->assertSame(5_000_000.0, $copy->getRows()->reset()->getQuantity());
    }

    /**
     * @return Tab<RecipeRow>
     */
    private function rows(int $count): Tab
    {
        $rows = Tab::newEmptyTyped(RecipeRow::class);
        for ($i = 0; $i < $count; $i++) {
            $rows[] = RecipeRow::create('row-' . $i, 'recipe-id', $this->ingredient(), 10.0, new UnitBuilder()->isGramme()->build());
        }

        return $rows;
    }

    /**
     * @param Tab<RecipeRow> $rows
     */
    private function create(int $serving, Tab $rows): Recipe
    {
        return Recipe::create('recipe-id', 'Pâtes', $serving, self::AUTHOR, new RecipeTypeBuilder()->isMeal()->build(), $rows, null);
    }
}

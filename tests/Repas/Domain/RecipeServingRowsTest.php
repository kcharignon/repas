<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * Les lignes d'une recette mises à l'échelle d'un nombre de personnes (`Recipe::getRowForServing`) sont des COPIES : la recette garde ses
 * quantités. Un repas d'une recette éclatée entre plusieurs listes (ADR 0036, LST-005) les demande une fois par repas du groupe.
 */
class RecipeServingRowsTest extends TestCase
{
    public function testScalingTheRowsLeavesTheRecipeUntouchedSoItCanBeScaledAgain(): void
    {
        // Given : 4 personnes, 500 g de farine
        $recipe = new RecipeBuilder()->withServing(4)->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isFlour())->withUnit(new UnitBuilder()->isGramme())->withQuantity(500))->build();

        // When
        $forEight = $recipe->getRowForServing(8);
        $forTwo = $recipe->getRowForServing(2);
        $forFour = $recipe->getRowForServing(4);

        // Then
        $quantity = static fn($rows): float => $rows->reset()->getQuantity();
        $this->assertSame([1000.0, 250.0, 500.0], [$quantity($forEight), $quantity($forTwo), $quantity($forFour)]);
        $this->assertSame(500.0, $recipe->getRows()->reset()->getQuantity());
        $this->assertInstanceOf(RecipeRow::class, $forEight->reset());
        $this->assertNotSame($recipe->getRows()->reset(), $forEight->reset());
    }
}

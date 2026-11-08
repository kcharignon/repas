<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Modifier ou supprimer la recette SANS PROPRIÉTAIRE (la neutre d'une recette Marmiton, ADR 0037 amendement du 2026-11-08, MAR-005) ne lit pas plus
 * quand la recette a plus de lignes, ni quand elle a plus de copies. Le budget par scénario (`budgets.php`) fige le total ; ce test prouve que la
 * part des LECTURES est constante dans les cas ordinaires : l'éditeur renvoie les lignes réservées telles quelles, ou les ingrédients de
 * l'administrateur dont le propriétaire réservé a déjà une copie (rien n'est alors copié) ; supprimer ne touche aucune copie.
 */
class RecipeWithoutOwnerReadsAreConstantTest extends WriteBudgetTestCase
{
    public function testEditingWithTheRowsOfTheNeutralAsTheyAreReadsTheSameWhateverTheNumberOfRows(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedEdit('alpha', 3, reserved: true)),
            $this->selects(fn() => $this->arrangedEdit('omega', 12, reserved: true)),
        );
    }

    public function testEditingWithIngredientsOfTheAdministratorWhoseReservedCopyExistsReadsTheSameWhateverTheNumberOfRows(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedEdit('sigma', 3, reserved: false)),
            $this->selects(fn() => $this->arrangedEdit('theta', 12, reserved: false)),
        );
    }

    public function testRemovingReadsAndWritesTheSameWhateverTheNumberOfCopies(): void
    {
        $this->assertSame(
            $this->total(fn() => $this->arrangedRemoval(1)),
            $this->total(fn() => $this->arrangedRemoval(10)),
        );
    }

    /**
     * @param callable(): object $arrange
     */
    private function selects(callable $arrange): int
    {
        [, $byTable] = $this->measure(fn() => $arrange());

        return array_sum(array_filter($byTable, static fn(string $key): bool => str_starts_with($key, 'SELECT'), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param callable(): object $arrange
     */
    private function total(callable $arrange): int
    {
        [$count] = $this->measure(fn() => $arrange());

        return $count;
    }

    private function arrangedEdit(string $prefix, int $rows, bool $reserved): UpdateRecipeCommand
    {
        $this->savedMarmitonReferenceData();
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $lines = [];
        $neutralRows = [];
        for ($index = 1; $index <= $rows; $index++) {
            $mine = new IngredientBuilder()->withName($prefix . $index)->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
                ->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->withCreator($admin, true)->build();
            $this->persister->saveIngredient($mine);
            // Le propriétaire réservé en a déjà une copie (même origine) : c'est le cas ordinaire d'une neutre déjà corrigée une fois
            $copy = new IngredientBuilder()->withName($prefix . $index)->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
                ->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->withCreatorId(Recipe::MARMITON_OWNER_ID)->withAdoptedFrom($mine->getSlug())->build();
            $this->persister->saveIngredient($copy);
            $neutralRows[] = new RecipeRowBuilder()->withIngredient($copy)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100);
            $lines[] = new UpdateRecipeRowSubCommand(($reserved ? $copy : $mine)->getSlug(), 'gramme', 150.0);
        }
        $neutral = new RecipeBuilder()->asMarmitonNeutral($prefix)->withName('Tarte ' . $prefix);
        foreach ($neutralRows as $row) {
            $neutral->addRow($row);
        }
        $recipe = $neutral->build();
        $this->persister->saveRecipe($recipe);

        return new UpdateRecipeCommand($recipe->getId(), 'Tarte corrigée', 4, new Tab($lines, UpdateRecipeRowSubCommand::class), $recipe->getType()->getSlug(), userId: $admin->getId(), byAdministrator: true);
    }

    private function arrangedRemoval(int $copies): RemoveRecipeCommand
    {
        $neutral = new RecipeBuilder()->isPastaCarbonara()->asMarmitonNeutral('22082')->withAuthorId(Recipe::MARMITON_OWNER_ID)->build();
        foreach ($neutral->getRows() as $row) {
            $row->getIngredient()->setCreatorId(Recipe::MARMITON_OWNER_ID);
        }
        $this->persister->saveRecipe($neutral);
        for ($index = 0; $index < $copies; $index++) {
            $this->savedRecipe($this->savedUser(), new RecipeBuilder()->isPastaCarbonara()->withOriginalId($neutral->getId()));
        }

        return new RemoveRecipeCommand($neutral->getId(), byAdministrator: true);
    }
}

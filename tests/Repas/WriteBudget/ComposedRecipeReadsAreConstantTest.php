<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\ComposedRecipeDataset;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Planifier, changer les personnes et retirer une recette qui en utilise d'autres ne lisent pas plus quand elle en utilise plus, quand leurs recettes ont
 * plus de lignes, quand le même ingrédient est répété à tous les niveaux, ni quand la recette est éclatée entre plusieurs listes (ADR 0040 décisions 12 et
 * 13, COMP-004) : les recettes utilisées se lisent en lot, les conversions et les destinations en une requête chacune. Seules les écritures (une
 * instruction par ligne qui change) suivent le nombre de lignes, comme toute écriture d'une liste. Le budget par scénario (`budgets.php`) fige le total ;
 * ce test prouve que la part des lectures ne dépend ni du nombre de recettes utilisées, ni de leurs lignes, ni de leur répétition.
 */
class ComposedRecipeReadsAreConstantTest extends WriteBudgetTestCase
{
    /** @return iterable<string, array{int, int, bool, bool}> composants, lignes par composant, ingrédients partagés, recette éclatée */
    public static function shapes(): iterable
    {
        yield 'un composant d\'une ligne' => [1, 1, false, false];
        yield 'cinq composants d\'une ligne' => [5, 1, false, false];
        yield 'dix composants de six lignes' => [10, 6, false, false];
        yield 'dix composants de six lignes, les mêmes ingrédients partout' => [10, 6, true, false];
        yield 'six composants éclatés entre trois listes' => [6, 3, false, true];
    }

    public function testPlanningReadsTheSameWhateverTheNumberOfUsedRecipesOrTheirRows(): void
    {
        foreach (self::reads(fn(bool $shared, bool $split, int $c, int $r) => $this->arrangedPlanning($c, $r, $shared, $split)) as $label => $selects) {
            $this->assertSame(self::baseline(fn(bool $split) => $this->arrangedPlanning(1, 1, false, $split), $label), $selects, $label);
        }
    }

    public function testRaisingThePeopleReadsTheSameWhateverTheNumberOfUsedRecipesOrTheirRows(): void
    {
        foreach (self::reads(fn(bool $shared, bool $split, int $c, int $r) => $this->arrangedServing($c, $r, $shared, $split, 6)) as $label => $selects) {
            $this->assertSame(self::baseline(fn(bool $split) => $this->arrangedServing(1, 1, false, $split, 6), $label), $selects, $label);
        }
    }

    public function testLoweringThePeopleReadsTheSameWhateverTheNumberOfUsedRecipesOrTheirRows(): void
    {
        foreach (self::reads(fn(bool $shared, bool $split, int $c, int $r) => $this->arrangedServing($c, $r, $shared, $split, 2)) as $label => $selects) {
            $this->assertSame(self::baseline(fn(bool $split) => $this->arrangedServing(1, 1, false, $split, 2), $label), $selects, $label);
        }
    }

    public function testRemovingTheRecipeReadsTheSameWhateverTheNumberOfUsedRecipesOrTheirRows(): void
    {
        foreach (self::reads(fn(bool $shared, bool $split, int $c, int $r) => $this->arrangedRemoval($c, $r, $shared, $split)) as $label => $selects) {
            $this->assertSame(self::baseline(fn(bool $split) => $this->arrangedRemoval(1, 1, false, $split), $label), $selects, $label);
        }
    }

    public function testTheUsedRecipesAreReadInOneBatchNotOnePerRecipe(): void
    {
        // Given / When : une recette qui en utilise une, puis dix
        $one = $this->tableReads(fn() => $this->arrangedPlanning(1, 2, false, false));
        $ten = $this->tableReads(fn() => $this->arrangedPlanning(10, 2, false, false));

        // Then : une lecture des arêtes de la recette, une des recettes, de leurs lignes et de leurs types, quel que soit leur nombre
        foreach (['SELECT recipe_component', 'SELECT recipe_row', 'SELECT recipe_type', 'SELECT recipe', 'SELECT conversion'] as $table) {
            $this->assertSame($one[$table] ?? 0, $ten[$table] ?? 0, $table);
        }
        $this->assertSame(1, $ten['SELECT recipe_component'] ?? 0);
    }

    /**
     * Pour chaque forme, le nombre de lectures du geste mesuré.
     *
     * @param callable(bool, bool, int, int): object $arrange
     * @return array<string, int>
     */
    private function reads(callable $arrange): array
    {
        $reads = [];
        foreach (self::shapes() as $label => [$components, $rows, $shared, $split]) {
            $reads[$label . ($split ? '#split' : '#plain')] = $this->selects(fn() => $arrange($shared, $split, $components, $rows));
        }

        return $reads;
    }

    /**
     * Le nombre de lectures de la forme la plus simple (un composant d'une ligne), éclatée ou non comme `$label`.
     *
     * @param callable(bool): object $arrange
     */
    private function baseline(callable $arrange, string $label): int
    {
        return $this->selects(fn() => $arrange(str_ends_with($label, '#split')));
    }

    /**
     * @param callable(): object $arrange
     */
    private function selects(callable $arrange): int
    {
        return array_sum(array_filter($this->tableReads($arrange), static fn(string $key): bool => str_starts_with($key, 'SELECT'), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param callable(): object $arrange
     * @return array<string, int>
     */
    private function tableReads(callable $arrange): array
    {
        [, $byTable] = $this->measure(fn() => $arrange());

        return $byTable;
    }

    private function arrangedPlanning(int $components, int $rows, bool $shared, bool $split): PlannedMealCommand
    {
        [$owner, $recipe] = ComposedRecipeDataset::create($this, $components, $rows, $shared, $split);

        return new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing());
    }

    private function arrangedServing(int $components, int $rows, bool $shared, bool $split, int $serving): UpdateServingMealCommand
    {
        [, , $mealId] = ComposedRecipeDataset::create($this, $components, $rows, $shared, $split, planned: true);

        return new UpdateServingMealCommand($mealId, $serving);
    }

    private function arrangedRemoval(int $components, int $rows, bool $shared, bool $split): removeMealFromPlanCommand
    {
        [$owner, $recipe] = ComposedRecipeDataset::create($this, $components, $rows, $shared, $split, planned: true);

        return new removeMealFromPlanCommand($owner->getId(), $recipe->getId());
    }
}

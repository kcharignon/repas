<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Planifier, changer les personnes, retirer une recette et ajouter une partie à la main ne lisent pas plus quand la recette a plus de parties, ni plus
 * de parents (ADR 0038 décision 14) : les parents se lisent en une requête, leurs conversions en une, et un retrait se décide d'après la liste, sans
 * rien lire. Seules les écritures (une instruction par ligne qui change) suivent le nombre de lignes, comme toute écriture d'une liste. Le budget par
 * scénario (`budgets.php`) fige le total ; ce test prouve que la part des lectures ne dépend pas du nombre de parties.
 */
class PartsReadsAreConstantTest extends WriteBudgetTestCase
{
    public function testPlanningReadsTheSameWhateverTheNumberOfPartsOrParents(): void
    {
        $one = $this->selects(fn() => $this->arrangedPlanning(1, 1));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedPlanning(1, 6)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedPlanning(4, 2)));
    }

    public function testRaisingThePeopleReadsTheSameWhateverTheNumberOfPartsOrParents(): void
    {
        $one = $this->selects(fn() => $this->arrangedServing(1, 1, 6));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedServing(1, 6, 6)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedServing(4, 2, 6)));
    }

    public function testLoweringThePeopleReadsTheSameWhateverTheNumberOfPartsOrParents(): void
    {
        $one = $this->selects(fn() => $this->arrangedServing(1, 1, 2));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedServing(1, 6, 2)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedServing(4, 2, 2)));
    }

    public function testRemovingTheRecipeReadsTheSameWhateverTheNumberOfPartsOrParents(): void
    {
        $one = $this->selects(fn() => $this->arrangedRemoval(1, 1));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedRemoval(1, 6)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedRemoval(4, 2)));
    }

    public function testRemovingARecipeOfPartsReadsNoParentAtAll(): void
    {
        // Given / When : le retrait se décide d'après le registre de la liste
        [, $byTable] = $this->measure(fn() => $this->arrangedRemoval(3, 3));

        // Then : les lectures sont celles d'une recette ordinaire retirée (recette, lignes, conversions), aucune lecture groupée d'ingrédients de plus
        [, $ordinary] = $this->measure(function (): removeMealFromPlanCommand {
            $owner = $this->savedUser();
            [$first] = $recipes = $this->typicalRecipes($owner);
            $this->savedShoppingList($owner, $recipes);

            return new removeMealFromPlanCommand($owner->getId(), $first->getId());
        });
        $this->assertSame($ordinary['SELECT ingredient'] ?? 0, $byTable['SELECT ingredient'] ?? 0);
    }

    public function testAddingAPartByHandReadsTheSameWhateverTheNumberOfParts(): void
    {
        $one = $this->selects(fn() => $this->arrangedAdding(1, 1));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedAdding(1, 6)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedAdding(4, 2)));
    }

    public function testRemovingAPartByHandReadsTheSameWhateverTheNumberOfParts(): void
    {
        $one = $this->selects(fn() => $this->arrangedHandRemoval(1, 1));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedHandRemoval(1, 6)));
        $this->assertSame($one, $this->selects(fn() => $this->arrangedHandRemoval(4, 2)));
    }

    /**
     * @param callable(): object $arrange
     */
    private function selects(callable $arrange): int
    {
        [, $byTable] = $this->measure(fn() => $arrange());

        return array_sum(array_filter($byTable, static fn(string $key): bool => str_starts_with($key, 'SELECT'), ARRAY_FILTER_USE_KEY));
    }

    private function arrangedPlanning(int $parents, int $partsPerParent): PlannedMealCommand
    {
        [$owner, $recipe] = $this->arrange($parents, $partsPerParent);

        return new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing());
    }

    private function arrangedServing(int $parents, int $partsPerParent, int $serving): UpdateServingMealCommand
    {
        [$owner, $recipe] = $this->arrange($parents, $partsPerParent);

        return new UpdateServingMealCommand($this->planned($owner, $recipe), $serving);
    }

    private function arrangedRemoval(int $parents, int $partsPerParent): removeMealFromPlanCommand
    {
        [$owner, $recipe] = $this->arrange($parents, $partsPerParent);
        $this->planned($owner, $recipe);

        return new removeMealFromPlanCommand($owner->getId(), $recipe->getId());
    }

    private function arrangedAdding(int $parents, int $partsPerParent): AddIngredientToShoppingListCommand
    {
        [$owner, $recipe, $part] = $this->arrange($parents, $partsPerParent);
        $this->planned($owner, $recipe);

        return new AddIngredientToShoppingListCommand($owner->getId(), $part->getSlug());
    }

    private function arrangedHandRemoval(int $parents, int $partsPerParent): RemoveIngredientToShoppingListCommand
    {
        [$owner, $recipe, $part] = $this->arrange($parents, $partsPerParent);
        $this->planned($owner, $recipe);

        return new RemoveIngredientToShoppingListCommand($owner->getId(), $part->getSlug());
    }

    /**
     * La recette est planifiée dans la liste principale, par le vrai cas d'usage ; renvoie l'identifiant de son repas.
     */
    private function planned(User $owner, Recipe $recipe): string
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing()));

        return static::getContainer()->get(ShoppingListRepository::class)->findOneActivateByOwner($owner->getId())->mealOf($recipe)->getId();
    }

    /**
     * Un utilisateur dont la liste principale planifie les trois recettes typiques, et une recette de `$parents` parents, chacun avec `$partsPerParent`
     * parties, la recette portant chaque parent et chacune de ses parties.
     *
     * @return array{0: User, 1: Recipe, 2: Ingredient} la première partie
     */
    private function arrange(int $parents, int $partsPerParent): array
    {
        $owner = $this->savedUser();
        $this->savedShoppingList($owner, $this->typicalRecipes($owner));

        $recipe = new RecipeBuilder()->withName('Grand gâteau')->withServing(4);
        $firstPart = null;
        for ($p = 0; $p < $parents; $p++) {
            $parent = new IngredientBuilder()->isEgg()->withName("Parent $p")->withCreator($owner, true)->build();
            $this->persister->saveIngredient($parent);
            $recipe->addRow(new RecipeRowBuilder()->withIngredient($parent)->withUnit(new UnitBuilder()->isUnite())->withQuantity(2));
            for ($i = 0; $i < $partsPerParent; $i++) {
                $part = new IngredientBuilder()->isEgg()->withName("Partie $p-$i")->withCreator($owner, true)->withParent($parent->getSlug(), 1.0)->build();
                $this->persister->saveIngredient($part);
                $firstPart ??= $part;
                $recipe->addRow(new RecipeRowBuilder()->withIngredient($part)->withUnit(new UnitBuilder()->isUnite())->withQuantity(1 + $i));
            }
        }

        return [$owner, $this->savedRecipe($owner, $recipe), $firstPart];
    }
}

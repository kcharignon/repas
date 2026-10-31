<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Planifier, changer les personnes et retirer une recette éclatée entre trois listes (ADR 0036, LST-005) ne lisent pas plus quand la recette a plus de
 * produits : les destinations se lisent en une requête, les conversions en une, les produits en une. Seules les écritures (une instruction par ligne qui
 * change) suivent le nombre de lignes, comme toute écriture d'une liste. Le budget par scénario (`budgets.php`) fige le total ; ce test prouve que la
 * part des lectures ne dépend pas du nombre de produits.
 */
class SplitRecipeReadsAreConstantTest extends WriteBudgetTestCase
{
    public function testPlanningReadsTheSameWhateverTheNumberOfProducts(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedPlanning(1)),
            $this->selects(fn() => $this->arrangedPlanning(6)),
        );
    }

    public function testChangingThePeopleReadsTheSameWhateverTheNumberOfProducts(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedServing(1)),
            $this->selects(fn() => $this->arrangedServing(6)),
        );
    }

    public function testRemovingTheRecipeReadsTheSameWhateverTheNumberOfProducts(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedRemoval(1)),
            $this->selects(fn() => $this->arrangedRemoval(6)),
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

    private function arrangedPlanning(int $productsPerDestination): PlannedMealCommand
    {
        [$owner, $recipe] = $this->arrange($productsPerDestination);

        return new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing());
    }

    private function arrangedServing(int $productsPerDestination): UpdateServingMealCommand
    {
        [$owner, $recipe] = $this->arrange($productsPerDestination);
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing()));
        $main = static::getContainer()->get(ShoppingListRepository::class)->findOneActivateByOwner($owner->getId());

        return new UpdateServingMealCommand($main->mealOf($recipe)->getId(), 6);
    }

    private function arrangedRemoval(int $productsPerDestination): removeMealFromPlanCommand
    {
        [$owner, $recipe] = $this->arrange($productsPerDestination);
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing()));

        return new removeMealFromPlanCommand($owner->getId(), $recipe->getId());
    }

    /**
     * Un utilisateur dont les rayons viande et fromage sont dans deux types, une liste en cours pour chacun et pour la liste principale, et une recette
     * de `3 * $productsPerDestination` produits (au gramme, achetés au kilo) répartis entre les trois destinations.
     *
     * @return array{0: User, 1: Recipe}
     */
    private function arrange(int $productsPerDestination): array
    {
        $owner = $this->savedUser();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $butcher = ShoppingListType::create(UuidGenerator::new(), $owner->getId(), 'Boucherie', new \DateTimeImmutable())->replaceDepartments(['viande']);
        $creamery = ShoppingListType::create(UuidGenerator::new(), $owner->getId(), 'Crèmerie', new \DateTimeImmutable())->replaceDepartments(['fromage']);
        $this->persister->saveShoppingListType($butcher);
        $this->persister->saveShoppingListType($creamery);
        $this->savedShoppingList($owner, []);
        $this->savedShoppingList($owner, [], static fn(ShoppingListBuilder $b) => $b->withListTypeId($butcher->getId()));
        $this->savedShoppingList($owner, [], static fn(ShoppingListBuilder $b) => $b->withListTypeId($creamery->getId()));

        $recipe = new RecipeBuilder()->withName('Grande recette')->withServing(4);
        foreach ([new DepartmentBuilder()->isMeat(), new DepartmentBuilder()->isCheese(), new DepartmentBuilder()->isMiscellaneous()] as $d => $department) {
            for ($i = 0; $i < $productsPerDestination; $i++) {
                $ingredient = new IngredientBuilder()->withName("Produit $d-$i")->withDepartment($department)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($owner, true)->build();
                $this->persister->saveIngredient($ingredient);
                $recipe->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
            }
        }

        return [$owner, $this->savedRecipe($owner, $recipe)];
    }
}

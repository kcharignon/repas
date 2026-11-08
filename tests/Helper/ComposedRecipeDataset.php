<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
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
use Repas\User\Domain\Model\User;

/**
 * Les données d'une recette qui en utilise d'autres (ADR 0040, COMP-004) sur la vraie base, pour les budgets SQL et les tests de lectures constantes :
 * un utilisateur dont la liste principale planifie déjà les trois recettes typiques, `$components` recettes utilisées de `$rowsPerComponent` lignes
 * chacune (au gramme, achetées au kilo) et « Grande tarte », qui les utilise toutes et porte trois lignes à elle (une par destination quand les produits sont répartis).
 */
final class ComposedRecipeDataset
{
    /**
     * @param bool $sharedIngredients les recettes utilisées et la tarte emploient les MÊMES ingrédients (un ingrédient répété à tous les niveaux)
     * @param bool $split             les produits se répartissent entre la liste principale, une liste « Boucherie » (viande) et une liste « Crèmerie » (fromage),
     *                                ces deux listes étant en cours (ADR 0036) ; faux : tout va dans la liste principale
     * @param bool $planned           « Grande tarte » est déjà planifiée (de quoi changer ses personnes ou la retirer)
     *
     * @return array{0: User, 1: Recipe, 2: ?string} le propriétaire, la recette composée, l'identifiant de son repas dans la liste principale (si `$planned`)
     */
    public static function create(WriteBudgetTestCase $t, int $components, int $rowsPerComponent, bool $sharedIngredients = false, bool $split = false, bool $planned = false): array
    {
        $owner = $t->savedUser();
        $t->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        if ($split) {
            foreach ([['Boucherie', 'viande'], ['Crèmerie', 'fromage']] as [$name, $department]) {
                $type = ShoppingListType::create(UuidGenerator::new(), $owner->getId(), $name, new \DateTimeImmutable())->replaceDepartments([$department]);
                $t->persister->saveShoppingListType($type);
                $t->savedShoppingList($owner, [], static fn(ShoppingListBuilder $b) => $b->withListTypeId($type->getId()));
            }
        }

        $row = static function (string $name, int $position) use ($owner, $split): RecipeRowBuilder {
            $department = $split ? match ($position % 3) {
                0 => new DepartmentBuilder()->isMeat(),
                1 => new DepartmentBuilder()->isCheese(),
                default => new DepartmentBuilder()->isMiscellaneous(),
            } : new DepartmentBuilder()->isMiscellaneous();
            $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit(new UnitBuilder()->isGramme())
                ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($owner, true)->build();

            return new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100);
        };

        $parent = new RecipeBuilder()->withName('Grande tarte')->withServing(4)
            ->addRow($row($sharedIngredients ? 'Produit 0' : 'Produit propre 0', 0))
            ->addRow($row($sharedIngredients ? 'Produit 1' : 'Produit propre 1', 1))
            ->addRow($row($sharedIngredients ? 'Produit 2' : 'Produit propre 2', 2));
        for ($c = 0; $c < $components; $c++) {
            $used = new RecipeBuilder()->withName("Base $c")->withServing(2 + $c % 3);
            for ($r = 0; $r < $rowsPerComponent; $r++) {
                $used->addRow($row($sharedIngredients ? "Produit $r" : "Produit $c-$r", $sharedIngredients ? $r : $r + $c));
            }
            // Des multiplicateurs variés : 1, 1,25, 1,5…
            $parent->withComponent($t->savedRecipe($owner, $used), 1.0 + ($c % 4) / 4);
        }
        $recipe = $t->savedRecipe($owner, $parent);

        $mealId = null;
        if ($planned) {
            $t->service(CommandBusInterface::class)->dispatch(new PlannedMealCommand($owner->getId(), $recipe->getId(), $owner->getDefaultServing()));
            $mealId = $t->service(ShoppingListRepository::class)->findOneActivateByOwner($owner->getId())->mealOf($recipe)->getId();
        }

        return [$owner, $recipe, $mealId];
    }
}

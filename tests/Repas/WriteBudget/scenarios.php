<?php

use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentCommand;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateRecipeType\CreateRecipeTypeCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitCommand;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Repas\Application\Command\PausedShoppingList\PausedShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\Command\UncheckLineOnShoppingList\UncheckLineOnShoppingListCommand;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Command\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;

/**
 * Un scénario = un use case d'écriture mesuré sur une situation typique : un utilisateur avec trois recettes
 * (carbonara, œufs à la coque, gâteau basque) et une liste de courses active qui les planifie. La fonction crée les
 * données et renvoie la commande à exécuter.
 *
 * @return array<string, callable(WriteBudgetTestCase): object>
 */

/** Ligne de courses d'une liste, par son rang. */
$row = static fn(ShoppingList $list, int $index = 0): ShoppingListRow => array_values($list->getRows()->toArray())[$index];

/** @return Tab<CreateRecipeRowSubCommand> */
$createRows = static fn(Recipe $recipe): Tab => new Tab(
    array_map(
        static fn($r) => new CreateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()),
        array_values($recipe->getRows()->toArray()),
    ),
    CreateRecipeRowSubCommand::class,
);

/** @return Tab<UpdateRecipeRowSubCommand> */
$updateRows = static fn(Recipe $recipe): Tab => new Tab(
    array_map(
        static fn($r) => new UpdateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()),
        array_values($recipe->getRows()->toArray()),
    ),
    UpdateRecipeRowSubCommand::class,
);

return [
    // --- Listes de courses
    'tick-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new TickLineOnShoppingListCommand($row($list)->getId());
    },
    // Dernière ligne cochée : le listener termine la liste (StoppedShoppingList)
    'tick-derniere-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), null, static function (ShoppingList $list): void {
            $rows = array_values($list->getRows()->toArray());
            foreach (array_slice($rows, 1) as $other) {
                $other->tick();
            }
        });

        return new TickLineOnShoppingListCommand($row($list)->getId());
    },
    'decocher-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), null, static function (ShoppingList $list): void {
            array_values($list->getRows()->toArray())[0]->tick();
        });

        return new UncheckLineOnShoppingListCommand($row($list)->getId());
    },
    'modifier-portions-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new UpdateServingMealCommand($list->getMeals()->reset()->getId(), 6);
    },
    'planifier-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        [$first, $second, $third] = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, [$first, $second]);

        return new PlannedMealCommand($owner->getId(), $third->getId());
    },
    'retirer-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        [$first] = $recipes = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, $recipes);

        return new removeMealFromPlanCommand($owner->getId(), $first->getId());
    },
    'ajouter-ingredient-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $milk = new IngredientBuilder()->isMilk()->build();
        $t->persister->saveIngredient($milk);

        return new AddIngredientToShoppingListCommand($owner->getId(), $milk->getSlug());
    },
    'retirer-ingredient-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $milk = new IngredientBuilder()->isMilk()->build();
        $t->persister->saveIngredient($milk);
        $t->savedShoppingList($owner, $t->typicalRecipes($owner), static fn(ShoppingListBuilder $b) => $b->addIngredient($milk));

        return new RemoveIngredientToShoppingListCommand($owner->getId(), $milk->getSlug());
    },
    'terminer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new StoppedShoppingListCommand($list->getId());
    },
    'mettre-liste-en-pause' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new PausedShoppingListCommand($list->getId());
    },
    'reactiver-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), static fn(ShoppingListBuilder $b) => $b->withStatus(ShoppingListStatus::PAUSED));

        return new ActivatedShoppingListCommand($list->getId());
    },
    'renommer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new UpdateShoppingListCommand($list->getId(), 'Courses de la semaine');
    },
    'creer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();

        return new CreateShoppingListCommand(UuidGenerator::new(), $owner->getId());
    },
    'assurer-liste-active' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();

        return new EnsureActiveShoppingListCommand(UuidGenerator::new(), $owner->getId());
    },
    'supprimer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new RemoveShoppingListCommand($list->getId());
    },

    // --- Recettes
    'creer-recette' => static function (WriteBudgetTestCase $t) use ($createRows): object {
        $owner = $t->savedUser();
        $model = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());

        return new CreateRecipeCommand(UuidGenerator::new(), 'Carbonara bis', 4, $owner->getId(), $createRows($model), $model->getType()->getSlug());
    },
    'modifier-recette' => static function (WriteBudgetTestCase $t) use ($updateRows): object {
        $owner = $t->savedUser();
        $recipe = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());

        return new UpdateRecipeCommand($recipe->getId(), 'Carbonara revisitée', 4, $updateRows($recipe), $recipe->getType()->getSlug());
    },
    'copier-recette' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $other = $t->savedUser();
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara());

        return new CopyRecipeCommand($recipe->getId(), $other->getId(), UuidGenerator::new());
    },
    'supprimer-recette' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $recipe = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());

        return new RemoveRecipeCommand($recipe->getId());
    },

    // --- Ingrédients et conversions
    'creer-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $department = new DepartmentBuilder()->isBakery()->build();
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveDepartment($department);
        $t->persister->saveUnit($unit);

        return new CreateIngredientCommand('Praliné', '', $department->getSlug(), $unit->getSlug(), $unit->getSlug(), $owner->getId(), null);
    },
    'modifier-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $t->persister->saveIngredient($ingredient);

        return new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'Farine complète',
            '',
            $ingredient->getDepartment()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            null,
        );
    },
    'supprimer-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $t->persister->saveIngredient($ingredient);

        return new RemoveIngredientCommand($ingredient->getSlug());
    },
    'creer-conversion' => static function (WriteBudgetTestCase $t): object {
        $t->persister->saveUnit(new UnitBuilder()->isLiter()->build());
        $t->persister->saveUnit(new UnitBuilder()->isMillilitre()->build());

        return new CreateConversionCommand(UuidGenerator::new(), 'litre', 'millilitre', 1000.0, null);
    },
    'modifier-conversion' => static function (WriteBudgetTestCase $t): object {
        $conversion = new ConversionBuilder()->isKiloToGramme()->build();
        $t->persister->saveConversion($conversion);

        return new UpdateConversionCommand($conversion->getId(), 'kilo', 'gramme', 1001.0, null);
    },

    // --- Référentiels
    'creer-unite' => static fn(WriteBudgetTestCase $t): object => new CreateUnitCommand('Pincée', 'pin'),
    'modifier-unite' => static function (WriteBudgetTestCase $t): object {
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveUnit($unit);

        return new UpdateUnitCommand($unit->getSlug(), 'Gramme', 'gr');
    },
    'creer-rayon' => static fn(WriteBudgetTestCase $t): object => new CreateDepartmentCommand('Surgelés', 'images/frozen.svg'),
    'modifier-rayon' => static function (WriteBudgetTestCase $t): object {
        $department = new DepartmentBuilder()->isBakery()->build();
        $t->persister->saveDepartment($department);

        return new UpdateDepartmentCommand($department->getSlug(), 'Boulangerie', 'images/bakery.svg');
    },
    'creer-type-recette' => static fn(WriteBudgetTestCase $t): object => new CreateRecipeTypeCommand('Apéritif', 'images/aperitif.svg', 9),
    'modifier-type-recette' => static function (WriteBudgetTestCase $t): object {
        $type = new RecipeTypeBuilder()->isMeal()->build();
        $t->persister->saveRecipeType($type);

        return new UpdateRecipeTypeCommand($type->getId(), 'Plat principal', $type->getImage(), 2);
    },

    // --- Utilisateurs
    'inscription' => static fn(WriteBudgetTestCase $t): object => new RegisterNewUserCommand('nouveau@example.com', 'mot-de-passe', 3),
    'reglages-utilisateur' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();

        return new UpdateUserSettingsCommand($user->getId(), null, 5);
    },
];

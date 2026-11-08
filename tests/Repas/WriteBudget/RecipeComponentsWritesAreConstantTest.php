<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Écrire les recettes qu'une recette utilise ne LIT pas plus quand elle en utilise plus (ADR 0040 décisions 26 et 27, COMP-002) : le verrou de l'auteur, la
 * lecture en lot des recettes utilisées, la question « une autre recette l'utilise-t-elle ? » et la lecture des arêtes sont une requête chacun, que la
 * recette en utilise une ou dix. Seules les écritures (une instruction par arête créée ou mise à jour) suivent le nombre de composants, comme toute écriture.
 * Le budget par scénario (`budgets.php`) fige le total ; ce test prouve que la part des lectures est constante.
 */
class RecipeComponentsWritesAreConstantTest extends WriteBudgetTestCase
{
    public function testCreatingReadsTheSameWhateverTheNumberOfUsedRecipes(): void
    {
        $this->assertSame($this->selects(fn() => $this->arrangedCreation(1)), $this->selects(fn() => $this->arrangedCreation(10)));
    }

    public function testReplacingReadsTheSameWhateverTheNumberOfUsedRecipes(): void
    {
        $this->assertSame($this->selects(fn() => $this->arrangedReplacement(1)), $this->selects(fn() => $this->arrangedReplacement(10)));
    }

    public function testRemovingAComposedRecipeReadsTheSameWhateverTheNumberOfUsedRecipes(): void
    {
        $this->assertSame($this->selects(fn() => $this->arrangedRemoval(1)), $this->selects(fn() => $this->arrangedRemoval(10)));
    }

    public function testTheUsedRecipesAreReadInOneBatchNotOnePerRecipe(): void
    {
        // Given / When : une recette qui en utilise une, puis dix
        $one = $this->tableReads(fn() => $this->arrangedReplacement(1));
        $ten = $this->tableReads(fn() => $this->arrangedReplacement(10));

        // Then : les recettes, leurs lignes, leurs types et les arêtes, une lecture de chaque quel que soit leur nombre
        foreach (['SELECT recipe_component', 'SELECT recipe_row', 'SELECT recipe_type', 'SELECT recipe', 'SELECT ingredient', 'SELECT unit', 'SELECT ?'] as $reads) {
            $this->assertSame($one[$reads] ?? 0, $ten[$reads] ?? 0, $reads);
        }
        // et les écritures suivent le nombre d'arêtes mises à jour
        $this->assertSame(1, $one['UPDATE recipe_component'] ?? 0);
        $this->assertSame(10, $ten['UPDATE recipe_component'] ?? 0);
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

    private int $arrangements = 0;

    /**
     * Une recette de deux lignes (au gramme, achetées au kilo) dont les ingrédients n'appartiennent qu'à elle : deux arrangements d'un même essai ne se
     * croisent pas (un ingrédient reste à celui qui l'a enregistré le premier).
     */
    private function recipe(User $owner, string $name, int $arrangement): RecipeBuilder
    {
        $builder = new RecipeBuilder()->withName($name)->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal());
        foreach (['a', 'b'] as $letter) {
            $ingredient = new IngredientBuilder()->withName("Produit $arrangement $name $letter")->withDefaultCookingUnit(new UnitBuilder()->isGramme())
                ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($owner, true)->build();
            $this->persister->saveIngredient($ingredient);
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
        }

        return $builder;
    }

    /**
     * @return list<Recipe> `$count` recettes de l'auteur, à utiliser
     */
    private function usedRecipes(User $owner, int $count, int $arrangement): array
    {
        $recipes = [];
        for ($number = 0; $number < $count; $number++) {
            $recipes[] = $this->savedRecipe($owner, $this->recipe($owner, "Base $number", $arrangement), ownedByAuthor: false);
        }

        return $recipes;
    }

    /**
     * @param list<Recipe> $recipes
     * @return Tab<RecipeComponentSubCommand>
     */
    private function components(array $recipes, float $multiplier): Tab
    {
        return new Tab(
            array_map(static fn(Recipe $recipe): RecipeComponentSubCommand => new RecipeComponentSubCommand($recipe->getId(), $multiplier), $recipes),
            RecipeComponentSubCommand::class,
        );
    }

    /**
     * @return array{User, Recipe, list<Recipe>} l'auteur, la recette modèle (sans composant) et `$components` recettes à utiliser
     */
    private function arrangement(int $components, bool $composed): array
    {
        $arrangement = ++$this->arrangements;
        $owner = $this->savedUser();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $used = $this->usedRecipes($owner, $components, $arrangement);
        $model = $this->recipe($owner, 'Tarte', $arrangement);
        foreach ($composed ? $used : [] as $recipe) {
            $model->withComponent($recipe, 1.0);
        }

        return [$owner, $this->savedRecipe($owner, $model, ownedByAuthor: false), $used];
    }

    private function arrangedCreation(int $components): CreateRecipeCommand
    {
        [$owner, $model, $used] = $this->arrangement($components, composed: false);

        return new CreateRecipeCommand(
            UuidGenerator::new(), 'Tarte bis', 4, $owner->getId(),
            new Tab(array_map(static fn($r): CreateRecipeRowSubCommand => new CreateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()), array_values($model->getRows()->toArray())), CreateRecipeRowSubCommand::class),
            $model->getType()->getSlug(),
            components: $this->components($used, 2.0),
        );
    }

    private function arrangedReplacement(int $components): UpdateRecipeCommand
    {
        [$owner, $pie, $used] = $this->arrangement($components, composed: true);

        return new UpdateRecipeCommand(
            $pie->getId(), 'Tarte revisitée', 4,
            new Tab(array_map(static fn($r): UpdateRecipeRowSubCommand => new UpdateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()), array_values($pie->getRows()->toArray())), UpdateRecipeRowSubCommand::class),
            $pie->getType()->getSlug(),
            userId: $owner->getId(),
            components: $this->components($used, 2.0),
        );
    }

    private function arrangedRemoval(int $components): RemoveRecipeCommand
    {
        [, $pie] = $this->arrangement($components, composed: true);

        return new RemoveRecipeCommand($pie->getId());
    }
}

<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
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
 * Copier une recette composée ne LIT pas plus quand elle utilise plus de recettes (ADR 0040 décisions 33 à 35, COMP-003) : le verrou de l'auteur de la copie, la
 * lecture en lot des recettes utilisées, la recherche des copies que le copieur a déjà, les arêtes de la recette, les lignes et les types sont UNE lecture chacun
 * que la recette en utilise une ou dix, réutilisées ou non, et le rapprochement des ingrédients est celui de l'union de leurs lignes (ici les mêmes ingrédients
 * partout : il ne dépend que de ce qu'il y a à rapprocher). Seules les écritures suivent le nombre de recettes copiées, comme toute écriture, avec la recherche de
 * l'identifiant libre et les lignes déjà enregistrées que l'enregistrement d'une recette neuve cherche (`SELECT recipe` et `SELECT recipe_row`, une de chaque par recette CRÉÉE) : une copie réutilisée ne coûte donc rien.
 * Le budget par scénario (`budgets.php`) fige le total ; ce test prouve que la part des lectures est constante.
 */
class CopyComposedRecipeReadsAreConstantTest extends WriteBudgetTestCase
{
    /** Les lectures qui ne dépendent ni du nombre de recettes utilisées ni de celles qui sont réutilisées. */
    private const array CONSTANT_READS = ['SELECT recipe_component', 'SELECT recipe_type', 'SELECT ingredient', 'SELECT unit', 'SELECT conversion', 'SELECT ingredient_alias', 'SELECT ?'];

    public function testCopyingReadsTheSameWhateverTheNumberOfUsedRecipes(): void
    {
        // Given / When : une recette qui en utilise une, puis cinq, puis dix
        $one = $this->tableReads(fn() => $this->arranged(1, 0));
        $five = $this->tableReads(fn() => $this->arranged(5, 0));
        $ten = $this->tableReads(fn() => $this->arranged(10, 0));

        // Then : une lecture de chaque table, le verrou et l'unique lecture des arêtes comprises
        foreach (self::CONSTANT_READS as $reads) {
            $this->assertSame($one[$reads] ?? 0, $five[$reads] ?? 0, $reads);
            $this->assertSame($one[$reads] ?? 0, $ten[$reads] ?? 0, $reads);
        }
        $this->assertSame(1, $ten['SELECT recipe_component'] ?? 0);
        $this->assertSame(1, $ten['SELECT ?'] ?? 0, 'le verrou de l\'auteur de la copie, une fois');
        // et seules les écritures suivent le nombre de recettes copiées : une recette neuve cherche son identifiant et ses lignes déjà écrites, puis s'écrit
        $this->assertSame(($one['SELECT recipe'] ?? 0) + 9, $ten['SELECT recipe'] ?? 0);
        $this->assertSame(($one['SELECT recipe_row'] ?? 0) + 9, $ten['SELECT recipe_row'] ?? 0);
        $this->assertSame(2, $one['INSERT recipe'] ?? 0);
        $this->assertSame(11, $ten['INSERT recipe'] ?? 0);
        $this->assertSame(1, $one['INSERT recipe_component'] ?? 0);
        $this->assertSame(10, $ten['INSERT recipe_component'] ?? 0);
    }

    public function testAReusedCopyCostsNothingMoreThanNotHavingTheRecipeAtAll(): void
    {
        // Given / When : dix recettes utilisées dont cinq ont déjà une copie du copieur, face à une recette qui n'en utilise que cinq
        $reused = $this->tableReads(fn() => $this->arranged(10, 5));
        $five = $this->tableReads(fn() => $this->arranged(5, 0));

        // Then : exactement les mêmes lectures et les mêmes écritures de recettes (six créées : la tarte et cinq recettes utilisées), dix arêtes écrites
        foreach ([...self::CONSTANT_READS, 'SELECT recipe', 'SELECT recipe_row', 'INSERT recipe'] as $reads) {
            $this->assertSame($five[$reads] ?? 0, $reused[$reads] ?? 0, $reads);
        }
        $this->assertSame(6, $reused['INSERT recipe'] ?? 0);
        $this->assertSame(10, $reused['INSERT recipe_component'] ?? 0);
    }

    public function testASharedCompositionOfTheAuthorReadsTheSameWhateverTheNumberOfUsedRecipes(): void
    {
        // Given / When : l'auteur copie sa propre recette composée (les recettes utilisées sont partagées, rien n'est recopié)
        $one = $this->tableReads(fn() => $this->arranged(1, 0, byAuthor: true));
        $ten = $this->tableReads(fn() => $this->arranged(10, 0, byAuthor: true));

        // Then : les mêmes lectures, une seule recette créée, les arêtes seules suivent le nombre de recettes utilisées
        foreach ([...self::CONSTANT_READS, 'SELECT recipe', 'SELECT recipe_row', 'INSERT recipe'] as $reads) {
            $this->assertSame($one[$reads] ?? 0, $ten[$reads] ?? 0, $reads);
        }
        $this->assertSame(1, $ten['INSERT recipe'] ?? 0);
        $this->assertSame(10, $ten['INSERT recipe_component'] ?? 0);
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
     * Une recette de deux lignes (au gramme, achetées au kilo) dont les ingrédients sont ceux de l'auteur, les MÊMES pour toutes ses recettes : le rapprochement
     * a toujours les deux mêmes ingrédients à traiter, quel que soit le nombre de recettes.
     */
    private function recipe(User $owner, string $name, int $arrangement, string $ingredients = 'Produit'): RecipeBuilder
    {
        $builder = new RecipeBuilder()->withName($name)->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal());
        foreach (['a', 'b'] as $letter) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($this->ingredient($owner, "$ingredients $arrangement $letter"))->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
        }

        return $builder;
    }

    private function ingredient(User $owner, string $name): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @param int $used      le nombre de recettes que la tarte utilise
     * @param int $reused    parmi elles, combien le copieur a déjà copiées (sa copie sert, rien n'est recopié)
     * @param bool $byAuthor l'auteur copie sa propre recette
     */
    private function arranged(int $used, int $reused, bool $byAuthor = false): CopyRecipeCommand
    {
        $arrangement = ++$this->arrangements;
        $author = $this->savedUser();
        $copier = $byAuthor ? $author : $this->savedUser();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        /** @var list<Recipe> $usedRecipes */
        $usedRecipes = [];
        for ($number = 0; $number < $used; $number++) {
            $usedRecipes[] = $this->savedRecipe($author, $this->recipe($author, "Base $number", $arrangement), ownedByAuthor: false);
        }
        $pie = $this->recipe($author, 'Tarte', $arrangement);
        foreach ($usedRecipes as $recipe) {
            $pie->withComponent($recipe, 1.0);
        }
        $pie = $this->savedRecipe($author, $pie, ownedByAuthor: false);

        // Les copies que le copieur a déjà (de l'ingrédient à lui, sans rapport avec ceux de l'auteur)
        foreach (array_slice($usedRecipes, 0, $reused) as $number => $recipe) {
            $this->savedRecipe($copier, $this->recipe($copier, "Copie $number", $arrangement, 'Autre')->withOriginalId($recipe->getId()), ownedByAuthor: false);
        }

        return new CopyRecipeCommand($pie->getId(), $copier->getId(), UuidGenerator::new());
    }
}

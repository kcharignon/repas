<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Lire une recette ne coûte pas plus de requêtes quand elle utilise plus de recettes, ni quand celles-ci ont plus de lignes (ADR 0040 décision 20) :
 * sans composant exactement les trois lectures d'aujourd'hui (la recette, ses lignes, le graphe des conversions), avec composants UNE de plus
 * (les arêtes jointes aux recettes utilisées), les lignes de toutes les recettes dans l'instruction des lignes et le graphe de tous les
 * ingrédients en une lecture.
 */
class RecipeComponentsReadsAreConstantTest extends DatabaseTestCase
{
    private RecipeLoader $recipeLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeLoader = static::getContainer()->get(RecipeLoader::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
    }

    public function testWithoutComponentTheReadIsTheThreeQueriesOfToday(): void
    {
        // Given & When
        [$count, $byTable] = $this->readOf($this->composedRecipe(0, 3));

        // Then
        $this->assertSame(3, $count);
        $this->assertEquals(['SELECT recipe' => 1, 'SELECT recipe_row' => 1, 'SELECT conversion' => 1], $byTable);
    }

    public function testComponentsCostOneQueryMoreWhateverTheirNumberOrTheirLines(): void
    {
        // Given & When
        [$one, $oneByTable] = $this->readOf($this->composedRecipe(1, 1));
        [$five] = $this->readOf($this->composedRecipe(5, 1));
        [$ten, $tenByTable] = $this->readOf($this->composedRecipe(10, 1));
        [$tenWithManyLines] = $this->readOf($this->composedRecipe(10, 6));

        // Then : les trois lectures, plus les arêtes
        $this->assertSame(4, $one);
        $this->assertSame($one, $five);
        $this->assertSame($one, $ten);
        $this->assertSame($one, $tenWithManyLines);
        $this->assertEquals(['SELECT recipe' => 1, 'SELECT recipe_row' => 1, 'SELECT conversion' => 1, 'SELECT recipe_component' => 1], $oneByTable);
        $this->assertEquals($oneByTable, $tenByTable);
    }

    /**
     * @return array{int, array<string, int>}
     */
    private function readOf(Recipe $recipe): array
    {
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $result = $this->recipeLoader->get($recipe->getId(), $recipe->getAuthorId());

        // Les lignes de toutes les recettes sont bien lues : le nombre de requêtes ne vient pas d'une lecture vide
        $this->assertCount($recipe->getComponents()->count(), $result->components);
        foreach ($result->components as $component) {
            $this->assertNotEmpty($component->rows);
        }

        return [$counter->count(), $counter->byTable()];
    }

    /**
     * Une recette de trois lignes qui utilise `$components` recettes de `$lines` lignes chacune, d'ingrédients tous différents.
     */
    private function composedRecipe(int $components, int $lines): Recipe
    {
        $author = $this->savedUser();
        $builder = new RecipeBuilder()->withAuthor($author)->withName('Recette composée');
        for ($line = 1; $line <= 3; ++$line) {
            $builder->addRow($this->row($author, "propre $line"));
        }
        for ($index = 1; $index <= $components; ++$index) {
            $used = new RecipeBuilder()->withAuthor($author)->withName("Recette utilisée $index");
            for ($line = 1; $line <= $lines; ++$line) {
                $used->addRow($this->row($author, "utilisée $index ligne $line"));
            }
            $builder->withComponent($this->saved($used->build()), 1.0 + $index);
        }

        return $this->saved($builder->build());
    }

    private function row(User $author, string $name): RecipeRowBuilder
    {
        return new RecipeRowBuilder()
            ->withIngredient(new IngredientBuilder()->withName("zzz $name")->withCreator($author, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo()))
            ->withUnit(new UnitBuilder()->isGramme())
            ->withQuantity(100);
    }

    private function saved(Recipe $recipe): Recipe
    {
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }
}

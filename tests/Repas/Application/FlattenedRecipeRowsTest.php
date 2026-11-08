<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Service\FlattenedRecipeRows;
use Repas\Repas\Domain\Service\RecipeFlattener;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;

/**
 * Le service qui lit les recettes utilisées d'une recette avant de l'aplatir (ADR 0040 décision 12) : rien lu sans composant, une seule lecture en lot
 * avec, et une recette utilisée que le lecteur ne voit pas est ignorée (décision 21).
 */
class FlattenedRecipeRowsTest extends TestCase
{
    public function testARecipeWithoutComponentReadsNothing(): void
    {
        // Given
        $recipes = new RecipeInMemoryRepository();
        $plain = $this->recipe('plain', 'author');
        $recipes->save($plain);

        // When
        $flat = new FlattenedRecipeRows($recipes, new RecipeFlattener())->of($plain, 'author');

        // Then : ses seules lignes, aucune lecture en lot
        $this->assertSame(0, $recipes->batchReads);
        $this->assertCount(1, $flat->allRows());
        $this->assertSame(200.0, $flat->forServing(8)->rows()->reset()->getQuantity());
    }

    public function testTheUsedRecipesOfAComposedRecipeAreReadOnceInABatch(): void
    {
        // Given
        $recipes = new RecipeInMemoryRepository();
        $first = $this->recipe('first', 'author');
        $second = $this->recipe('second', 'author');
        $pie = $this->recipe('pie', 'author', [$first, $second]);
        array_map($recipes->save(...), [$first, $second, $pie]);

        // When
        $flat = new FlattenedRecipeRows($recipes, new RecipeFlattener())->of($pie, 'author');

        // Then : une lecture, trois lignes (la sienne et celle de chaque recette utilisée)
        $this->assertSame(1, $recipes->batchReads);
        $this->assertCount(3, $flat->allRows());
    }

    public function testASecretUsedRecipeIsKeptForItsAuthorAndIgnoredForAnotherReader(): void
    {
        // Given : la tarte de l'auteur utilise une recette secrète à lui
        $recipes = new RecipeInMemoryRepository();
        $secret = $this->recipe('secret', 'author', secret: true);
        $pie = $this->recipe('pie', 'author', [$secret], secret: true);
        array_map($recipes->save(...), [$secret, $pie]);
        $service = new FlattenedRecipeRows($recipes, new RecipeFlattener());

        // When & Then : son auteur la voit, un autre lecteur (un repas hérité) jamais
        $this->assertCount(2, $service->of($pie, 'author')->allRows());
        $this->assertCount(1, $service->of($pie, 'someone-else')->allRows());
    }

    public function testAMissingUsedRecipeIsIgnored(): void
    {
        // Given : la recette utilisée n'est pas (ou plus) enregistrée
        $recipes = new RecipeInMemoryRepository();
        $gone = $this->recipe('gone', 'author');
        $pie = $this->recipe('pie', 'author', [$gone]);
        $recipes->save($pie);

        // When
        $flat = new FlattenedRecipeRows($recipes, new RecipeFlattener())->of($pie, 'author');

        // Then
        $this->assertCount(1, $flat->allRows());
    }

    /**
     * @param list<\Repas\Repas\Domain\Model\Recipe> $uses
     */
    private function recipe(string $id, string $author, array $uses = [], bool $secret = false): \Repas\Repas\Domain\Model\Recipe
    {
        $builder = new RecipeBuilder()->withId($id)->withName("Recette $id")->withServing(4)->withAuthorId($author)->asSecret($secret)
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isFlour())->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
        foreach ($uses as $used) {
            $builder->withComponent($used, 1.0);
        }

        return $builder->build();
    }
}

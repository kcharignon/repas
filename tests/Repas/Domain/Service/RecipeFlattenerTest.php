<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\RecipeFlattener;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * La formule de l'ADR 0040 décision 11, sur des chiffres : pour une recette de `s` personnes et un repas de `n`, `k = n ÷ s`, les lignes de la recette
 * × `k` puis, pour chaque composant `(C, m)` dans l'ordre des positions, les lignes de `C` TELLES QU'ELLES SONT ÉCRITES × `m` × `k`. Des copies, aucune
 * fusion, aucun arrondi, jamais de récursion.
 */
class RecipeFlattenerTest extends TestCase
{
    private RecipeFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new RecipeFlattener();
    }

    public function testAPieForFourUsingADoughWrittenForSixOnceGivesTheDoughAsWritten(): void
    {
        // Given : la tarte pour 4 (100 g de sucre) utilise une fois la pâte écrite pour 6 (250 g de farine)
        [$pie, $dough] = $this->pieWithDough(multiplier: 1.0);

        // When
        $forFour = $this->flattener->flatten($pie, [$dough->getId() => $dough], 4);
        $forEight = $this->flattener->flatten($pie, [$dough->getId() => $dough], 8);

        // Then : un repas de 4 = 250 g, un repas de 8 = 500 g (le nombre de personnes de la pâte n'intervient pas)
        $this->assertSame(['sucre' => 100.0, 'farine' => 250.0], $this->quantities($forFour->rows()));
        $this->assertSame(['sucre' => 200.0, 'farine' => 500.0], $this->quantities($forEight->rows()));
    }

    public function testTheMultiplierIsHowManyTimesTheDoughAsWritten(): void
    {
        // Given : « 2 fois » la pâte
        [$pie, $dough] = $this->pieWithDough(multiplier: 2.0);

        // When
        $forFour = $this->flattener->flatten($pie, [$dough->getId() => $dough], 4);

        // Then : 500 g de farine pour 4 personnes
        $this->assertSame(['sucre' => 100.0, 'farine' => 500.0], $this->quantities($forFour->rows()));
    }

    public function testFractionalMultipliersAndServingsAreNotRounded(): void
    {
        // Given : un demi de pâte, un repas de 3 pour une tarte de 4 : 250 × 0,5 × 0,75
        [$pie, $dough] = $this->pieWithDough(multiplier: 0.5);

        // When
        $rows = $this->flattener->flatten($pie, [$dough->getId() => $dough], 3)->rows();

        // Then : 75 g de sucre, 93,75 g de farine, aucun arrondi
        $this->assertSame(['sucre' => 75.0, 'farine' => 93.75], $this->quantities($rows));
    }

    public function testTheRowsAreCopiesSoTheModelsKeepTheirQuantitiesAndCanBeScaledAgain(): void
    {
        // Given
        [$pie, $dough] = $this->pieWithDough(multiplier: 2.0);
        $components = [$dough->getId() => $dough];

        // When : plusieurs mises à l'échelle de la même recette, comme un groupe de repas
        $first = $this->flattener->flatten($pie, $components, 8);
        $second = $this->flattener->flatten($pie, $components, 2);
        $third = $this->flattener->flatten($pie, $components, 4);

        // Then : chacune est juste, et ni la tarte ni la pâte n'ont bougé
        $this->assertSame(['sucre' => 200.0, 'farine' => 1000.0], $this->quantities($first->rows()));
        $this->assertSame(['sucre' => 50.0, 'farine' => 250.0], $this->quantities($second->rows()));
        $this->assertSame(['sucre' => 100.0, 'farine' => 500.0], $this->quantities($third->rows()));
        $this->assertSame(100.0, $pie->getRows()->reset()->getQuantity());
        $this->assertSame(250.0, $dough->getRows()->reset()->getQuantity());
        $this->assertNotSame($pie->getRows()->reset(), $first->rows()->reset());
        $this->assertNotSame($dough->getRows()->reset(), $first->rows()->toArray()[1]);
    }

    public function testTheRowsOfTheRecipeComeFirstThenThoseOfEachUsedRecipeInTheOrderOfTheirPositions(): void
    {
        // Given : trois recettes utilisées, dont les positions ne sont pas dans l'ordre où elles sont données
        $dough = $this->recipe('dough', 'Pâte', 6, [['farine', 250]]);
        $cream = $this->recipe('cream', 'Crème', 4, [['beurre', 100]]);
        $glaze = $this->recipe('glaze', 'Glaçage', 4, [['sucre', 50]]);
        $pie = Recipe::load($this->base('pie', 'Tarte', 4, [['oeuf', 2]]) + ['components' => Tab::fromArray(
            RecipeComponent::load(['id' => 'e-glaze', 'recipe_id' => 'pie', 'component_id' => 'glaze', 'multiplier' => 1.0, 'position' => 2]),
            RecipeComponent::load(['id' => 'e-dough', 'recipe_id' => 'pie', 'component_id' => 'dough', 'multiplier' => 1.0, 'position' => 0]),
            RecipeComponent::load(['id' => 'e-cream', 'recipe_id' => 'pie', 'component_id' => 'cream', 'multiplier' => 1.0, 'position' => 1]),
        )]);

        // When
        $rows = $this->flattener->flatten($pie, ['glaze' => $glaze, 'dough' => $dough, 'cream' => $cream], 4)->rows();

        // Then : ses lignes, puis la pâte (0), la crème (1), le glaçage (2)
        $this->assertSame(['oeuf', 'farine', 'beurre', 'sucre'], $this->slugs($rows));
    }

    public function testAnEdgeWhoseUsedRecipeIsMissingIsIgnoredNeverAnException(): void
    {
        // Given : la tarte utilise deux recettes, une seule est fournie (l'autre a été supprimée ou n'est pas lisible du lecteur)
        $dough = $this->recipe('dough', 'Pâte', 6, [['farine', 250]]);
        $pie = $this->composed('pie', 'Tarte', 4, [['sucre', 100]], [[$dough, 1.0], [$this->recipe('gone', 'Disparue', 4, [['beurre', 10]]), 3.0]]);

        // When
        $flat = $this->flattener->flatten($pie, ['dough' => $dough], 4);

        // Then
        $this->assertSame(['sucre' => 100.0, 'farine' => 250.0], $this->quantities($flat->rows()));
    }

    public function testRecipesThatTheRecipeDoesNotUseAreIgnored(): void
    {
        // Given : une recette fournie qui n'est pas un composant
        [$pie, $dough] = $this->pieWithDough(multiplier: 1.0);
        $stranger = $this->recipe('stranger', 'Autre', 4, [['beurre', 999]]);

        // When
        $flat = $this->flattener->flatten($pie, [$dough->getId() => $dough, 'stranger' => $stranger], 4);

        // Then
        $this->assertSame(['sucre' => 100.0, 'farine' => 250.0], $this->quantities($flat->rows()));
    }

    public function testTheComponentsOfAUsedRecipeAreIgnoredDepthIsOne(): void
    {
        // Given : (donnée invalide, que les écritures interdisent) la pâte utilise elle-même une crème
        $cream = $this->recipe('cream', 'Crème', 4, [['beurre', 100]]);
        $dough = $this->composed('dough', 'Pâte', 6, [['farine', 250]], [[$cream, 1.0]]);
        $pie = $this->composed('pie', 'Tarte', 4, [['sucre', 100]], [[$dough, 1.0]]);

        // When : même si la crème est fournie
        $flat = $this->flattener->flatten($pie, ['dough' => $dough, 'cream' => $cream], 4);

        // Then : les lignes de la pâte, jamais celles de la crème (aucune récursion, donc aucune boucle)
        $this->assertSame(['sucre' => 100.0, 'farine' => 250.0], $this->quantities($flat->rows()));
    }

    public function testARecipeThatUsesItselfOrIsCyclicNeverLoops(): void
    {
        // Given : (donnée invalide) la tarte et la pâte s'utilisent l'une l'autre
        $dough = $this->composed('dough', 'Pâte', 6, [['farine', 250]], [[$this->recipe('pie', 'Tarte', 4, []), 1.0]]);
        $pie = $this->composed('pie', 'Tarte', 4, [['sucre', 100]], [[$dough, 1.0], [$this->recipe('pie', 'Tarte', 4, []), 1.0]]);

        // When
        $flat = $this->flattener->flatten($pie, ['dough' => $dough, 'pie' => $pie], 4);

        // Then : ses lignes, celles de la pâte, et celles de la tarte elle-même une fois (sans composant lu), puis rien
        $this->assertSame(['sucre', 'farine', 'sucre'], $this->slugs($flat->rows()));
    }

    public function testTheSameIngredientAtBothLevelsIsTwoLinesNeverMerged(): void
    {
        // Given : du sucre dans la tarte (100 g) et dans la pâte (50 g)
        $dough = $this->recipe('dough', 'Pâte', 6, [['sucre', 50], ['farine', 250]]);
        $pie = $this->composed('pie', 'Tarte', 4, [['sucre', 100]], [[$dough, 1.0]]);

        // When
        $rows = $this->flattener->flatten($pie, ['dough' => $dough], 4)->rows();

        // Then : trois lignes, deux de sucre
        $this->assertSame(['sucre', 'sucre', 'farine'], $this->slugs($rows));
        $this->assertSame([100.0, 50.0, 250.0], array_map(static fn(RecipeRow $row): float => $row->getQuantity(), array_values($rows->toArray())));
    }

    public function testTheSameIngredientInTwoUnitsStaysInTwoLines(): void
    {
        // Given : de la farine en grammes dans la tarte, en cuillères dans la pâte
        $dough = $this->recipe('dough', 'Pâte', 6, [['farine', 5]], unit: 'soupSpoon');
        $pie = $this->composed('pie', 'Tarte', 4, [['farine', 100]], [[$dough, 1.0]]);

        // When
        $rows = $this->flattener->flatten($pie, ['dough' => $dough], 4)->rows();

        // Then : la conversion se fait plus tard, ligne à ligne
        $this->assertSame(['gramme', 'cuillere-a-soupe'], array_map(static fn(RecipeRow $row): string => $row->getUnit()->getSlug(), array_values($rows->toArray())));
    }

    public function testEachRowKnowsTheUsedRecipeItComesFrom(): void
    {
        // Given
        [$pie, $dough] = $this->pieWithDough(multiplier: 1.0);

        // When
        $flat = $this->flattener->flatten($pie, [$dough->getId() => $dough], 4);
        [$own, $fromDough] = array_values($flat->rows()->toArray());

        // Then : une ligne propre n'a pas de source, une ligne de la pâte la nomme ; une ligne étrangère n'en a pas non plus
        $this->assertNull($flat->sourceNameOf($own));
        $this->assertSame('Pâte sablée', $flat->sourceNameOf($fromDough));
        $this->assertNull($flat->sourceNameOf($dough->getRows()->reset()));
    }

    public function testASourceSurvivesFilteringTheRows(): void
    {
        // Given : les handlers gardent seulement les lignes que le repas a posées
        [$pie, $dough] = $this->pieWithDough(multiplier: 1.0);
        $flat = $this->flattener->flatten($pie, [$dough->getId() => $dough], 4);

        // When
        $kept = $flat->rows()->filter(static fn(RecipeRow $row): bool => $row->getIngredient()->getSlug() === 'farine');

        // Then : c'est le même objet, la source suit
        $this->assertSame('Pâte sablée', $flat->sourceNameOf($kept->reset()));
    }

    public function testAMissingConversionOfAUsedRecipeNamesItAndOtherFailuresPassUnchanged(): void
    {
        // Given
        [$pie, $dough] = $this->pieWithDough(multiplier: 1.0);
        $flat = $this->flattener->flatten($pie, [$dough->getId() => $dough], 4);
        [$own, $fromDough] = array_values($flat->rows()->toArray());
        $unit = new UnitBuilder()->isGramme()->build();
        $missing = static fn() => IngredientException::cannotConvertToUnit(new IngredientBuilder()->isFlour()->build(), $unit, $unit);

        // When
        $named = $flat->attribute($missing(), $fromDough);
        $ownLine = $flat->attribute($missing(), $own);
        $other = $flat->attribute(IngredientException::notFound('farine'), $fromDough);

        // Then
        $this->assertSame('Pâte sablée', $named->conversionRecipeName());
        $this->assertSame('Farine', $named->conversionIngredientName());
        $this->assertNull($ownLine->conversionRecipeName(), 'une ligne de la recette elle-même ne nomme aucune recette utilisée');
        $this->assertNull($other->conversionRecipeName());
        $this->assertSame(422, $named->getCode(), 'le code de la route de planification ne change pas');
    }

    public function testARecipeWithoutComponentIsScaledExactlyLikeTheRowsOfTheRecipe(): void
    {
        // Given : le cas d'avant les recettes utilisées
        $recipe = $this->recipe('plain', 'Gâteau', 4, [['farine', 500], ['oeuf', 3], ['sucre', 125]]);

        // When
        foreach ([1, 2, 4, 7, 12] as $serving) {
            $flattened = $this->flattener->flatten($recipe, [], $serving)->rows();
            $legacy = $recipe->getRowForServing($serving);

            // Then : mêmes lignes, même ordre, mêmes quantités
            $this->assertSame(
                array_map(static fn(RecipeRow $row): array => [$row->getIngredient()->getSlug(), $row->getUnit()->getSlug(), $row->getQuantity()], array_values($legacy->toArray())),
                array_map(static fn(RecipeRow $row): array => [$row->getIngredient()->getSlug(), $row->getUnit()->getSlug(), $row->getQuantity()], array_values($flattened->toArray())),
                "pour $serving personnes",
            );
        }
    }

    public function testTheLargestProductsStayFinite(): void
    {
        // Given : 1 000 000 g × 100 fois × (100 personnes ÷ 1 personne)
        $dough = $this->recipe('dough', 'Pâte', 1, [['farine', 1_000_000]]);
        $pie = $this->composed('pie', 'Tarte', 1, [['sucre', 1]], [[$dough, 100.0]]);

        // When
        $rows = $this->flattener->flatten($pie, ['dough' => $dough], 100)->rows();

        // Then : 10^10, un nombre fini
        $this->assertSame(10_000_000_000.0, $rows->toArray()[1]->getQuantity());
    }

    public function testAProductThatIsNotFiniteIsRefusedRatherThanWritten(): void
    {
        // Given : une ligne déjà énorme (une donnée plus ancienne que les bornes se lit telle quelle), mise à l'échelle par 100 × 100
        $huge = RecipeRow::load([
            'id' => 'huge', 'recipe_id' => 'big', 'ingredient' => new IngredientBuilder()->isFlour()->build(),
            'quantity' => 1.0e308, 'unit' => new UnitBuilder()->isGramme()->build(),
        ]);
        $big = Recipe::load(['rows' => Tab::fromArray($huge)] + $this->base('big', 'Énorme', 1, [['sucre', 1]]));
        $pie = $this->composed('pie', 'Tarte', 1, [['sucre', 1]], [[$big, 100.0]]);

        // Then
        $this->expectException(RecipeException::class);

        // When
        $this->flattener->flatten($pie, ['big' => $big], 100);
    }

    /**
     * @return array{Recipe, Recipe} la tarte (4 personnes, 100 g de sucre) qui utilise la pâte (6 personnes, 250 g de farine)
     */
    private function pieWithDough(float $multiplier): array
    {
        $dough = $this->recipe('dough', 'Pâte sablée', 6, [['farine', 250]]);

        return [$this->composed('pie', 'Tarte au citron', 4, [['sucre', 100]], [[$dough, $multiplier]]), $dough];
    }

    /**
     * @param list<array{string, int}> $rows
     * @param list<array{Recipe, float}> $components
     */
    private function composed(string $id, string $name, int $serving, array $rows, array $components): Recipe
    {
        $edges = [];
        foreach ($components as $position => [$used, $multiplier]) {
            $edges[] = RecipeComponent::load(['id' => "edge-$id-$position", 'recipe_id' => $id, 'component_id' => $used->getId(), 'multiplier' => $multiplier, 'position' => $position]);
        }

        return Recipe::load($this->base($id, $name, $serving, $rows) + ['components' => new Tab($edges, RecipeComponent::class)]);
    }

    /**
     * @param list<array{string, int}> $rows
     */
    private function recipe(string $id, string $name, int $serving, array $rows, string $unit = 'gramme'): Recipe
    {
        return Recipe::load($this->base($id, $name, $serving, $rows, $unit));
    }

    /**
     * @param list<array{string, int}> $rows ingrédient (oeuf, farine, sucre, beurre), quantité
     * @return array<string, mixed>
     */
    private function base(string $id, string $name, int $serving, array $rows, string $unit = 'gramme'): array
    {
        $builder = new RecipeBuilder()->withId($id)->withName($name)->withServing($serving);
        foreach ($rows as [$slug, $quantity]) {
            $ingredient = match ($slug) {
                'oeuf' => new IngredientBuilder()->isEgg(),
                'farine' => new IngredientBuilder()->isFlour(),
                'sucre' => new IngredientBuilder()->isSugar(),
                'beurre' => new IngredientBuilder()->isButter(),
                default => throw new \LogicException("Ingrédient de test inconnu : $slug"),
            };
            $unitBuilder = match (true) {
                $slug === 'oeuf' => new UnitBuilder()->isUnite(),
                $unit === 'soupSpoon' => new UnitBuilder()->isSoupSpoon(),
                default => new UnitBuilder()->isGramme(),
            };
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($unitBuilder)->withQuantity($quantity));
        }
        $recipe = $builder->build();

        return [
            'id' => $recipe->getId(), 'name' => $recipe->getName(), 'serving' => $recipe->getServing(), 'author_id' => $recipe->getAuthorId(),
            'type' => $recipe->getType(), 'rows' => $recipe->getRows(), 'original_id' => null,
        ];
    }

    /**
     * @param Tab<RecipeRow> $rows
     * @return array<string, float> la quantité de chaque ingrédient (un slug par ligne, la dernière gagne : ces tests n'en répètent pas)
     */
    private function quantities(Tab $rows): array
    {
        $quantities = [];
        foreach ($rows as $row) {
            $quantities[$row->getIngredient()->getSlug()] = $row->getQuantity();
        }

        return $quantities;
    }

    /**
     * @param Tab<RecipeRow> $rows
     * @return list<string>
     */
    private function slugs(Tab $rows): array
    {
        return array_values(array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $rows->toArray()));
    }
}

<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\ComposedScenario;

/**
 * L'invariant des recettes qui en utilisent d'autres (ADR 0040 décisions 11, 14 et 15), rejoué sur des séries pseudo-aléatoires de gestes à graine fixe :
 * planifier, retirer et changer les personnes de quatre recettes qui se mêlent (une pâte, une sauce, une tarte qui utilise la pâte et la sauce, une quiche
 * qui utilise la pâte deux fois), la pâte et la sauce pouvant aussi être planifiées seules. Après chaque geste, la liste est celle d'un modèle de référence
 * écrit À PART (une table de recettes et de la simple arithmétique, jamais l'aplatisseur) ; aucune ligne n'est nulle ou négative ; une fois tout retiré, la
 * liste est vide, sans poussière de virgule flottante.
 */
class ComposedInvariantTest extends TestCase
{
    /**
     * Les recettes : nombre de personnes d'écriture, lignes propres (`slug => quantité` en grammes, ou en unités pour l'œuf), recettes utilisées
     * (`id => multiplicateur`).
     */
    private const array RECIPES = [
        'dough-id' => ['serving' => 6, 'rows' => ['farine' => 250, 'beurre' => 125], 'uses' => []],
        'sauce-id' => ['serving' => 2, 'rows' => ['sucre' => 20, 'farine' => 10], 'uses' => []],
        'pie-id' => ['serving' => 4, 'rows' => ['sucre' => 100, 'oeuf' => 2], 'uses' => ['dough-id' => 1.0, 'sauce-id' => 0.5]],
        'quiche-id' => ['serving' => 6, 'rows' => ['oeuf' => 3, 'farine' => 40], 'uses' => ['dough-id' => 2.0]],
    ];

    /** Combien de grammes (ou d'unités) dans une unité d'achat : la farine et le sucre au kilo, le beurre en plaquettes de 250 g, l'œuf à l'unité. */
    private const array PURCHASE_UNIT = ['farine' => 1000.0, 'sucre' => 1000.0, 'beurre' => 250.0, 'oeuf' => 1.0];

    private const array SERVINGS = [1, 2, 3, 4, 6, 8, 12, 25];

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 7, 11, 42, 99, 123, 2026, 4242, 31337, 8112020] as $seed) {
            yield "graine $seed" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testTheListIsTheReferenceModelAfterEveryGesture(int $seed): void
    {
        // Given : la liste en cours et les quatre recettes
        $s = new ComposedScenario();
        $s->list();
        $recipes = $this->defineRecipes($s);
        $random = new Randomizer(new Mt19937($seed));
        /** @var array<string, int> $planned recette => nombre de personnes */
        $planned = [];
        $log = [];

        // When : 200 gestes au hasard
        for ($step = 1; $step <= 200; $step++) {
            $ids = array_keys(self::RECIPES);
            $id = $ids[$random->getInt(0, count($ids) - 1)];
            $gesture = $random->getInt(0, 9);

            if ($gesture <= 3 && !isset($planned[$id])) {
                $serving = self::SERVINGS[$random->getInt(0, count(self::SERVINGS) - 1)];
                ($s->planned())($s->plan($recipes[$id], $serving));
                $planned[$id] = $serving;
                $log[] = "planifier $id pour $serving";
            } elseif ($gesture <= 6 && isset($planned[$id])) {
                ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $id));
                unset($planned[$id]);
                $log[] = "retirer $id";
            } elseif (isset($planned[$id])) {
                $serving = self::SERVINGS[$random->getInt(0, count(self::SERVINGS) - 1)];
                ($s->serving())(new UpdateServingMealCommand($s->meal($s->reload(), $recipes[$id])->getId(), $serving));
                $planned[$id] = $serving;
                $log[] = "personnes de $id : $serving";
            } else {
                continue;
            }

            // Then : la liste est celle du modèle de référence
            $this->assertMatchesReference($s, $planned, "graine $seed, geste $step : " . end($log) . "\n" . implode("\n", array_slice($log, -8)));
        }

        // And : tout retiré, la liste est vide (ni ligne, ni repas)
        foreach (array_keys($planned) as $id) {
            ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $id));
        }
        $this->assertSame([], $s->rows($s->reload()), "graine $seed : une liste vidée n'a plus de ligne");
        $this->assertCount(0, $s->reload()->getMeals(), "graine $seed : une liste vidée n'a plus de repas");
    }

    /**
     * Les quatre recettes, enregistrées dans le dépôt de la recette (celles du scénario sont remplacées).
     *
     * @return array<string, Recipe>
     */
    private function defineRecipes(ComposedScenario $s): array
    {
        $ingredients = ['farine' => $s->flour, 'sucre' => $s->sugar, 'beurre' => $s->butter, 'oeuf' => $s->egg];
        $recipes = [];
        foreach (self::RECIPES as $id => $definition) {
            $builder = new RecipeBuilder()->withId($id)->withName("Recette $id")->withServing($definition['serving'])->withAuthor($s->owner);
            foreach ($definition['rows'] as $slug => $quantity) {
                $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredients[$slug])->withUnit($slug === 'oeuf' ? new UnitBuilder()->isUnite() : new UnitBuilder()->isGramme())->withQuantity($quantity));
            }
            foreach ($definition['uses'] as $usedId => $multiplier) {
                $builder->withComponent($recipes[$usedId], $multiplier);
            }
            $recipes[$id] = $builder->build();
            $s->recipes->save($recipes[$id]);
        }

        return $recipes;
    }

    /**
     * @param array<string, int> $planned recette => nombre de personnes
     */
    private function assertMatchesReference(ComposedScenario $s, array $planned, string $context): void
    {
        // Le modèle de référence : pour chaque recette planifiée, ses lignes et celles de ses recettes utilisées × multiplicateur, × personnes ÷ écriture
        $expected = [];
        foreach ($planned as $id => $serving) {
            $scale = $serving / self::RECIPES[$id]['serving'];
            foreach (self::RECIPES[$id]['rows'] as $slug => $quantity) {
                $expected[$slug] = ($expected[$slug] ?? 0.0) + $quantity * $scale;
            }
            foreach (self::RECIPES[$id]['uses'] as $usedId => $multiplier) {
                foreach (self::RECIPES[$usedId]['rows'] as $slug => $quantity) {
                    $expected[$slug] = ($expected[$slug] ?? 0.0) + $quantity * $multiplier * $scale;
                }
            }
        }
        foreach ($expected as $slug => $base) {
            $expected[$slug] = $base / self::PURCHASE_UNIT[$slug];
        }
        ksort($expected);

        $actual = $s->rows($s->reload());
        $bySlug = [];
        foreach ($actual as $slug => $quantity) {
            $this->assertGreaterThan(0.0, $quantity, "$context : $slug a une quantité nulle ou négative");
            $bySlug[$slug] = $quantity;
        }
        $this->assertSame(array_keys($expected), array_keys($bySlug), $context);
        foreach ($expected as $slug => $quantity) {
            $this->assertEqualsWithDelta($quantity, $bySlug[$slug], 1e-7, "$context : $slug");
        }
        $this->assertCount(count($planned), $s->reload()->getMeals(), $context);
    }
}

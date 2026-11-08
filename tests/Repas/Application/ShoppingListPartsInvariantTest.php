<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Tests\Helper\PartsScenario;

/**
 * L'invariant de la liste de courses quand des ingrédients sont des PARTIES d'autres (ADR 0038 décision 7), rejoué sur des séries pseudo-aléatoires de
 * gestes à graine fixe : planifier, retirer, changer les personnes, ajouter et retirer à la main, sur DEUX parents (« Œuf » : jaune et blanc, ratio 1 ;
 * « Gousse de vanille » : demi-gousse, ratio 2, et graines, ratio 4) et QUATRE parties, avec des recettes qui mêlent parent, parties et un ingrédient
 * ordinaire. Après chaque geste :
 *
 *     ligne du parent = quantité directe + plus grand équivalent de ses parties
 *
 * où la quantité directe et les équivalents sont recalculés À PART, par un modèle de référence qui ne connaît que les gestes (jamais le registre de la
 * liste) ; aucune ligne n'est nulle ou négative, aucune partie n'est une ligne, le registre ne garde aucune entrée sans sens.
 */
class ShoppingListPartsInvariantTest extends TestCase
{
    /** Les recettes, pour quatre personnes : `slug => quantité` (la farine en grammes, achetée au kilo). */
    private const array RECIPES = [
        'omelette' => ['oeuf' => 3],
        'mousse' => ['jaune' => 2],
        'meringue' => ['blanc' => 2],
        'creme' => ['jaune' => 3, 'blanc' => 1, 'oeuf' => 1],
        'flan' => ['demi-gousse' => 3, 'vanille' => 1],
        'chantilly' => ['graines' => 8, 'demi-gousse' => 1],
        'gateau' => ['oeuf' => 2, 'jaune' => 1, 'graines' => 4, 'farine' => 500],
        'pain' => ['farine' => 250],
    ];

    /** Ratio de chaque partie, par slug (le parent est dit à part). */
    private const array RATIOS = ['jaune' => 1.0, 'blanc' => 1.0, 'demi-gousse' => 2.0, 'graines' => 4.0];
    private const array PARENT_OF = ['jaune' => 'oeuf', 'blanc' => 'oeuf', 'demi-gousse' => 'vanille', 'graines' => 'vanille'];
    private const array SERVINGS = [1, 2, 3, 4, 6, 8, 12];
    private const array HAND = ['oeuf', 'jaune', 'blanc', 'vanille', 'demi-gousse', 'graines'];

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
    public function testTheInvariantHoldsAfterEveryGesture(int $seed): void
    {
        // Given : la liste en cours et les recettes
        $s = new PartsScenario();
        $s->list();
        foreach (self::RECIPES as $id => $rows) {
            $s->recipe($id, $rows);
        }
        $random = new Randomizer(new Mt19937($seed));
        /** @var array<string, int> $planned recette => nombre de personnes */
        $planned = [];
        /** @var array<string, int> $hand slug => nombre de fois ajouté à la main et pas encore retiré */
        $hand = [];
        $log = [];

        // When : 250 gestes au hasard
        for ($step = 1; $step <= 250; $step++) {
            $gesture = $random->getInt(0, 9);
            $recipeIds = array_keys(self::RECIPES);
            $recipeId = $recipeIds[$random->getInt(0, count($recipeIds) - 1)];
            $slug = self::HAND[$random->getInt(0, count(self::HAND) - 1)];

            if ($gesture <= 2 && !isset($planned[$recipeId])) {
                $serving = self::SERVINGS[$random->getInt(0, count(self::SERVINGS) - 1)];
                $s->plan($recipeId, $serving);
                $planned[$recipeId] = $serving;
                $log[] = "planifier $recipeId pour $serving";
            } elseif ($gesture <= 4 && isset($planned[$recipeId])) {
                $s->unplan($recipeId);
                unset($planned[$recipeId]);
                $log[] = "retirer $recipeId";
            } elseif ($gesture <= 6 && isset($planned[$recipeId])) {
                $serving = self::SERVINGS[$random->getInt(0, count(self::SERVINGS) - 1)];
                $s->changeServing($recipeId, $serving);
                $planned[$recipeId] = $serving;
                $log[] = "personnes de $recipeId : $serving";
            } elseif ($gesture <= 8) {
                $s->add($slug);
                $hand[$slug] = ($hand[$slug] ?? 0) + 1;
                $log[] = "ajouter $slug à la main";
            } elseif (($hand[$slug] ?? 0) > 0) {
                $s->remove($slug);
                $hand[$slug]--;
                $log[] = "retirer $slug à la main";
            } else {
                continue;
            }

            // Then : l'invariant, recalculé à part
            $this->assertInvariant($s, $planned, $hand, "graine $seed, geste $step : " . end($log) . "\n" . implode("\n", array_slice($log, -8)));
        }

        // And : tout retiré, la liste est vide (ni ligne, ni entrée)
        foreach (array_keys($planned) as $recipeId) {
            $s->unplan($recipeId);
        }
        foreach ($hand as $slug => $times) {
            for ($i = 0; $i < $times; $i++) {
                $s->remove($slug);
            }
        }
        $this->assertSame([], $s->rows(), "graine $seed : une liste vidée n'a plus de ligne");
        $this->assertSame([], $s->reload()->getPartShares(), "graine $seed : une liste vidée n'a plus de partie");
    }

    /**
     * @param array<string, int> $planned
     * @param array<string, int> $hand
     */
    private function assertInvariant(PartsScenario $s, array $planned, array $hand, string $context): void
    {
        // La quantité posée sur chaque ingrédient, dans son unité d'achat, rien que d'après les gestes
        $posted = [];
        foreach ($planned as $recipeId => $serving) {
            foreach (self::RECIPES[$recipeId] as $slug => $quantity) {
                $posted[$slug] = ($posted[$slug] ?? 0.0) + $quantity * $serving / 4 * ($slug === 'farine' ? 0.001 : 1.0);
            }
        }
        foreach ($hand as $slug => $times) {
            $posted[$slug] = ($posted[$slug] ?? 0.0) + $times;
        }

        // Le total attendu de chaque ligne : la quantité directe + le plus grand équivalent de ses parties
        $expected = [];
        foreach (['oeuf', 'vanille', 'farine'] as $parent) {
            $largest = 0.0;
            foreach (self::PARENT_OF as $part => $of) {
                if ($of === $parent) {
                    $largest = max($largest, ($posted[$part] ?? 0.0) / self::RATIOS[$part]);
                }
            }
            $total = ($posted[$parent] ?? 0.0) + $largest;
            if ($total > 1e-9) {
                $expected[$parent] = $total;
            }
        }

        ksort($expected);
        $actual = $s->rows();
        $this->assertSame(array_keys($expected), array_keys($actual), "Les lignes de la liste. $context");
        foreach ($expected as $slug => $total) {
            $this->assertEqualsWithDelta($total, $actual[$slug], 1e-9, "La ligne « $slug ». $context");
        }
        foreach ($actual as $slug => $quantity) {
            $this->assertGreaterThan(1e-9, $quantity, "Aucune ligne nulle ou négative ($slug). $context");
            $this->assertArrayNotHasKey($slug, self::PARENT_OF, "Une partie n'est jamais une ligne. $context");
        }

        // Le registre ne garde que des entrées qui ont un sens, et chaque ligne de parent couvre ses parties
        $list = $s->reload();
        foreach ($list->getPartShares() as $part => $share) {
            $this->assertGreaterThan(0.0, $share->quantity, "Entrée « $part ». $context");
            $this->assertGreaterThan(0.0, $share->equivalent, "Entrée « $part ». $context");
            $this->assertSame(self::PARENT_OF[$part], $share->parentSlug, $context);
            $this->assertGreaterThanOrEqual($share->equivalent - 1e-9, $list->findRow($share->parentSlug)->getQuantity(), "Le total couvre la partie « $part ». $context");
        }
        $this->assertListIsConsistent($list, $context);
    }

    private function assertListIsConsistent(ShoppingList $list, string $context): void
    {
        foreach ($list->getPartShares() as $part => $share) {
            $this->assertNotNull($list->findRow($share->parentSlug), "Une partie sans la ligne de son parent ($part). $context");
        }
    }
}

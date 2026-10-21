<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Le budget SQL de la fusion de deux ingrédients (ING-005) ne dépend pas du volume : 2 recettes et 2 listes, ou 20 et 20, coûtent le même
 * nombre de requêtes, et c'est celui du scénario `fusionner-ingredients` de `budgets.php`. Tout est repointé en masse, jamais une requête
 * par recette, par liste ou par ligne.
 *
 * La RÉCUPÉRATION des conversions de l'absorbé (amendement du 2026-10-21) coûte un nombre FIXE de requêtes de plus, lui aussi indépendant du
 * volume et du nombre de conversions récupérées : la copie est une seule requête, quel que soit le nombre de conversions. C'est celui du
 * scénario `fusionner-ingredients-avec-recuperation`.
 */
class MergeIngredientsBudgetTest extends WriteBudgetTestCase
{
    public function testTheNumberOfQueriesDoesNotDependOnTheNumberOfRecipesAndLists(): void
    {
        $counts = [];
        foreach ([[2, 2], [8, 6], [40, 30]] as [$recipes, $lists]) {
            // When : chaque volume est celui d'un utilisateur distinct, mesuré depuis une requête neuve
            [$count] = $this->measure(static fn(WriteBudgetTestCase $t) => $t->savedMerge($t->savedUser(), $recipes, $lists));
            $counts["$recipes recettes, $lists listes"] = $count;
        }

        // Then
        $this->assertCount(1, array_unique($counts), json_encode($counts, JSON_UNESCAPED_UNICODE));
        $this->assertSame((require __DIR__ . '/budgets.php')['fusionner-ingredients'], array_values($counts)[0]);
    }

    public function testRecoveringConversionsCostsAFixedNumberOfQueriesWhateverTheVolumeAndTheNumberOfConversions(): void
    {
        $counts = [];
        foreach ([[2, 2, 1], [8, 6, 2], [40, 30, 4]] as [$recipes, $lists, $conversions]) {
            // When
            [$count] = $this->measure(static fn(WriteBudgetTestCase $t) => $t->savedMerge($t->savedUser(), $recipes, $lists, $conversions));
            $counts["$recipes recettes, $lists listes, $conversions conversions récupérées"] = $count;
        }

        // Then : le même nombre de requêtes, et c'est le budget du scénario avec récupération
        $this->assertCount(1, array_unique($counts), json_encode($counts, JSON_UNESCAPED_UNICODE));
        $this->assertSame((require __DIR__ . '/budgets.php')['fusionner-ingredients-avec-recuperation'], array_values($counts)[0]);
    }
}

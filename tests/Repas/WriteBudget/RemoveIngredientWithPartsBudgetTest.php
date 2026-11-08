<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Supprimer un ingrédient détache ses parties (ADR 0038 décision 26, SUB-004) en UNE instruction, qui s'exécute toujours (l'ingrédient chargé ne sait pas
 * s'il a des parties) : supprimer un ingrédient qui n'a aucune partie, un qui en a une ou un qui en a dix coûte donc le même nombre de requêtes, et
 * c'est le budget du scénario `supprimer-ingredient` (une requête de plus qu'avant le découpage, annoncée).
 */
class RemoveIngredientWithPartsBudgetTest extends WriteBudgetTestCase
{
    public function testTheNumberOfQueriesDoesNotDependOnTheNumberOfParts(): void
    {
        $counts = [];
        foreach ([0, 1, 10] as $parts) {
            // When : un parent de 0, 1 puis 10 parties (le plafond)
            [$count] = $this->measure(function () use ($parts): RemoveIngredientCommand {
                $owner = $this->savedUser();
                $egg = new IngredientBuilder()->withName('Œuf')->withCreator($owner, true)->build();
                $this->persister->saveIngredient($egg);
                for ($i = 1; $i <= $parts; ++$i) {
                    $this->persister->saveIngredient(new IngredientBuilder()->withName("Partie $i")->withCreator($owner, true)->withParent($egg->getSlug(), 1.0)->build());
                }

                return new RemoveIngredientCommand($egg->getSlug());
            });
            $counts["$parts parties"] = $count;
        }

        // Then
        $this->assertCount(1, array_unique($counts), json_encode($counts, JSON_UNESCAPED_UNICODE));
    }
}

<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Le budget SQL de la migration d'adoption (ING-003) est constant EN NOMBRE DE COMPTES : chaque compte coûte le même nombre de requêtes,
 * que la commande migre un compte ou cinq, et ce nombre est celui du scénario `adopter-catalogue` de `budgets.php` (donc il ne dépend pas
 * non plus du volume : voir `tests/Command/AdoptionPerformanceTest`).
 */
class AdoptCatalogueBudgetTest extends WriteBudgetTestCase
{
    public function testEveryAccountCostsTheSameNumberOfQueriesWhetherOneOrFiveAreMigrated(): void
    {
        // Given : cinq comptes concernés de tailles différentes
        $users = [];
        foreach ([1, 2, 3, 4, 5] as $index) {
            $users[] = $user = $this->savedUser();
            $this->savedAdoptionVolume($user, recipes: $index, rowsPerRecipe: 3 * $index, lists: 2 + $index, rowsPerList: 2 * $index, choices: $index);
        }
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When : on les migre un à un, en mesurant chacun
        $perAccount = [];
        foreach ($users as $user) {
            $this->entityManager->clear();
            $counter->reset();
            $bus->dispatch(new AdoptCommonIngredientsForUserCommand($user->getId(), 'migration-essai', true));
            $perAccount[] = $counter->count();
        }

        // Then : le même nombre pour le premier que pour le cinquième, et c'est le budget du scénario
        $this->assertCount(1, array_unique($perAccount), 'requêtes par compte : ' . implode(', ', $perAccount));
        $this->assertSame((require __DIR__ . '/budgets.php')['adopter-catalogue'], $perAccount[0]);
    }
}

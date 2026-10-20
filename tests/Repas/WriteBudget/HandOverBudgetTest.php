<?php

namespace Repas\Tests\Repas\WriteBudget;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Le budget SQL de la passation du catalogue (ING-009) est CONSTANT : les anciens communs sont classés et passés au propriétaire par UN `UPDATE`
 * chacun, et les empreintes, les préconditions et la statistique coûtent le même nombre de requêtes quels que soient le nombre de recettes, de
 * listes et de choix du drive du propriétaire. Ce nombre est celui des scénarios `classer-ingredients-de-base` et `passation-communs` de
 * `budgets.php`.
 */
class HandOverBudgetTest extends WriteBudgetTestCase
{
    #[DataProvider('volumes')]
    public function testTheCostOfTheHandOverDoesNotDependOnTheVolumeOfTheOwnersData(int $recipes, int $rowsPerRecipe, int $lists, int $rowsPerList, int $choices): void
    {
        // When
        [$count, $byTable] = $this->measure(static function (WriteBudgetTestCase $t) use ($recipes, $rowsPerRecipe, $lists, $rowsPerList, $choices): object {
            $t->switchOnIngredientSeeding();
            $t->savedHandoverCatalog($recipes, $rowsPerRecipe, $lists, $rowsPerList, $choices);

            return new HandOverCatalogCommand();
        });

        // Then : le même nombre de requêtes, celui du budget ; et UN seul UPDATE du créateur des anciens communs, aucun INSERT ni DELETE
        $this->assertSame((require __DIR__ . '/budgets.php')['passation-communs'], $count, json_encode($byTable));
        $this->assertSame(1, $byTable['UPDATE ingredient'] ?? 0);
        $this->assertSame([], array_filter(array_keys($byTable), static fn(string $key): bool => str_starts_with($key, 'INSERT') || str_starts_with($key, 'DELETE')));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE slug LIKE 'commun-%' AND creator IS NULL"));
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function volumes(): iterable
    {
        yield 'un petit propriétaire' => [3, 4, 3, 4, 3];
        yield 'un gros propriétaire' => [40, 20, 12, 25, 120];
    }

    #[DataProvider('volumes')]
    public function testTheCostOfTheClassificationDoesNotDependOnTheVolumeEither(int $recipes, int $rowsPerRecipe, int $lists, int $rowsPerList, int $choices): void
    {
        // When
        [$count, $byTable] = $this->measure(static function (WriteBudgetTestCase $t) use ($recipes, $rowsPerRecipe, $lists, $rowsPerList, $choices): object {
            $t->savedHandoverCatalog($recipes, $rowsPerRecipe, $lists, $rowsPerList, $choices, classified: false);

            return new ClassifyBaseIngredientsCommand();
        });

        // Then : verrou, lecture des sans-créateur, UN UPDATE
        $this->assertSame((require __DIR__ . '/budgets.php')['classer-ingredients-de-base'], $count, json_encode($byTable));
        $this->assertSame(1, $byTable['UPDATE ingredient'] ?? 0);
        $this->assertSame(131, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE slug LIKE 'commun-%' AND is_base"));
    }
}

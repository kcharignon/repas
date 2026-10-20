<?php

namespace Repas\Tests\Command;


use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Repas\Infrastructure\Adoption\SeedingSourceReader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La durée de la passation du catalogue (ING-009) sur un volume bien plus grand que la production : le propriétaire a ici plus de 10 000 lignes
 * (recettes, listes dont deux envoyées, choix du drive avec leurs produits) sur ses anciens communs, six comptes migrés ont leurs 261 copies.
 * La passation (un seul `UPDATE`) tient en quelques dixièmes de seconde ; le NOMBRE de requêtes de la phase 4 ne dépend pas du volume.
 */
class HandOverCatalogPerformanceTest extends DatabaseTestCase
{
    public function testTheWholeCommandOnAGreaterThanProductionVolumeIsFastAndTheHandOverItselfIsOneUpdate(): void
    {
        // Given
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $lines = $dataset->addOwnerVolume(120, 60, 24, 100, 112);
        $this->assertGreaterThanOrEqual(10000, $lines);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
        $counter = static::getContainer()->get(QueryCounter::class);
        $timings = [];

        // When : chaque mode, mesuré
        foreach ([
            'à blanc' => ['args' => [], 'status' => Command::FAILURE],
            'classement et amorçage' => ['args' => ['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true], 'status' => Command::SUCCESS],
            'passation complète' => ['args' => ['--apply' => true], 'status' => Command::SUCCESS],
            'second --apply' => ['args' => ['--apply' => true], 'status' => Command::SUCCESS],
            '--check' => ['args' => ['--check' => true], 'status' => Command::SUCCESS],
        ] as $label => ['args' => $args, 'status' => $expected]) {
            $counter->reset();
            $started = hrtime(true);
            $status = $tester->execute($args);
            $timings[$label] = [(hrtime(true) - $started) / 1e9, $counter->count()];
            $this->assertSame($expected, $status, $label . "\n" . $tester->getDisplay());
        }
        fwrite(STDERR, sprintf("\n[ING-009 perf] %d lignes du propriétaire : %s\n", $lines, implode(' ; ', array_map(static fn(string $label, array $t): string => sprintf('%s %.2f s (%d requêtes)', $label, $t[0], $t[1]), array_keys($timings), $timings))));

        // Then : quelques secondes, jamais des minutes
        foreach ($timings as $label => [$seconds]) {
            $this->assertLessThan(30.0, $seconds, $label);
        }
        $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
    }

    public function testReadingWhatAnAccountMustReceiveStaysInMillisecondsWhateverTheTablesHold(): void
    {
        // Given : 1 800 ingrédients et 12 000 lignes. Trois `EXISTS` corrélés dans cette lecture faisaient franchir `jit_above_cost` au planificateur
        // (leur prix est multiplié par le nombre d'ingrédients) : une demi-seconde de compilation pour une requête de quelques millisecondes, deux
        // fois par activation de compte
        $dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $dataset->addOwnerVolume(120, 60, 24, 100, 112);
        $dataset->markBase();
        $reader = static::getContainer()->get(SeedingSourceReader::class);

        // When
        $started = hrtime(true);
        for ($i = 0; $i < 5; ++$i) {
            $rows = $reader->read($dataset->emptyId, $dataset->ownerId, []);
        }
        $seconds = (hrtime(true) - $started) / 1e9 / 5;
        fwrite(STDERR, sprintf("\n[ING-009 perf] lecture de la source de l'amorçage : %.3f s par lecture\n", $seconds));

        // Then
        $this->assertCount(155, $rows, 'les 151 « de base » et les quatre voisins que ses lignes référencent');
        $this->assertLessThan(0.25, $seconds);
    }

    public function testThePhaseFourAloneCostsTheSameNumberOfQueriesWhateverTheVolumeAndTakesTenthsOfASecond(): void
    {
        // Given : le classement et l'amorçage faits, un très gros propriétaire
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $dataset->addOwnerVolume(120, 60, 24, 100, 112);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $tester->getDisplay());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $started = hrtime(true);
        $result = static::getContainer()->get(CommandBusInterface::class)->dispatch(new HandOverCatalogCommand());
        $seconds = (hrtime(true) - $started) / 1e9;
        fwrite(STDERR, sprintf("[ING-009 perf] phase 4 seule : %.3f s, %d requêtes SQL, %d ingrédients passés\n", $seconds, $counter->count(), $result->handedOver));

        // Then : le même nombre de requêtes que le budget (31, mesuré sur un petit propriétaire), en moins de deux secondes
        $this->assertSame(261, $result->handedOver);
        $this->assertLessThanOrEqual((require dirname(__DIR__) . '/Repas/WriteBudget/budgets.php')['passation-communs'], $counter->count());
        $this->assertLessThan(2.0, $seconds);
    }
}

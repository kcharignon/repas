<?php

namespace Repas\Tests\Command;


use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La durée de la migration d'adoption (ING-003) sur un volume bien plus grand que la production : environ 10 000 lignes à repointer pour
 * un compte (recettes, lignes et ingrédients de listes dont deux envoyées, choix du drive avec leurs produits), 262 communs et 105
 * conversions propres. Elle doit tenir en quelques secondes, pas en minutes, et le NOMBRE de requêtes ne doit pas dépendre du volume
 * (quelques requêtes par compte, jamais une par commun ni par ligne).
 */
class AdoptionPerformanceTest extends DatabaseTestCase
{
    public function testTenThousandLinesAreMigratedInSecondsWithAConstantNumberOfQueries(): void
    {
        // Given : un petit compte et un très gros compte, sur le même catalogue de 262 communs
        $persister = new DataPersister(static::getContainer());
        $small = $this->savedUser($persister);
        $big = $this->savedUser($persister);
        AdoptionDataset::createBulk($this->entityManager, $persister, $small, 2, 3, 3, 4, 2);
        $volume = AdoptionDataset::createBulk($this->entityManager, $persister, $big, 100, 60, 20, 100, 100, false);
        $lines = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT (SELECT count(*) FROM recipe_row) + (SELECT count(*) FROM shopping_list_row) + (SELECT count(*) FROM shopping_list_ingredient) + (SELECT count(*) FROM drive_choice)',
        );
        $this->assertGreaterThanOrEqual(10000, $lines);
        $this->assertSame(262, $volume['commons']);

        $bus = static::getContainer()->get(CommandBusInterface::class);
        $counter = static::getContainer()->get(QueryCounter::class);
        $queries = [];

        // When : chaque compte, mesuré
        foreach (['small' => $small, 'big' => $big] as $name => $userId) {
            $this->entityManager->clear();
            $counter->reset();
            $started = hrtime(true);
            $result = $bus->dispatch(new AdoptCommonIngredientsForUserCommand($userId, 'migration-essai', true));
            $seconds = (hrtime(true) - $started) / 1e9;
            $queries[$name] = $counter->count();
            fwrite(STDERR, sprintf("\n[ING-003 perf] compte %s : %d requêtes SQL, %.2f s, %d copies, %d lignes de recettes, %d de listes\n", $name, $queries[$name], $seconds, $result->adopted, $result->repointed->recipeRows, $result->repointed->shoppingListRows));
            $this->assertSame(262, $result->adopted);
            $this->assertLessThan(30.0, $seconds, "le compte « $name » doit se migrer en quelques secondes");
        }

        // Then : le même nombre de requêtes quel que soit le volume
        $this->assertSame($queries['small'], $queries['big']);
        $this->assertLessThan(60, $queries['big']);
    }

    public function testTheWholeCommandOnTheBigVolumeAndItsNoopReplayAreFast(): void
    {
        // Given
        $persister = new DataPersister(static::getContainer());
        $user = $this->savedUser($persister);
        AdoptionDataset::createBulk($this->entityManager, $persister, $user, 100, 60, 20, 100, 100);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));

        // When
        $started = hrtime(true);
        $dryRun = $tester->execute([]);
        $dryRunSeconds = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $apply = $tester->execute(['--apply' => true, '--accept-sent' => true]);
        $applySeconds = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $replay = $tester->execute(['--apply' => true]);
        $replaySeconds = (hrtime(true) - $started) / 1e9;
        fwrite(STDERR, sprintf("\n[ING-003 perf] commande complète : à blanc %.2f s, --apply %.2f s, second --apply %.2f s\n", $dryRunSeconds, $applySeconds, $replaySeconds));

        // Then
        $this->assertSame([Command::SUCCESS, Command::SUCCESS, Command::SUCCESS], [$dryRun, $apply, $replay], $tester->getDisplay());
        $this->assertLessThan(30.0, $dryRunSeconds);
        $this->assertLessThan(60.0, $applySeconds);
        $this->assertLessThan(30.0, $replaySeconds);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE r.author = ? AND i.creator IS NULL',
            [$user],
        ));
    }

    private function savedUser(DataPersister $persister): string
    {
        $user = new UserBuilder()->build();
        $persister->saveUser($user);

        return $user->getId();
    }
}

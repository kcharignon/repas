<?php

namespace Repas\Tests\Command;


use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SeedingDataset;
use Repas\Tests\Helper\SeedingMeasures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le retour arrière des DONNÉES d'un amorçage (ING-008) : il n'a pas de commande à lui, la commande d'ING-003 (`repas:ingredients:adopt-commons
 * --undo=<lancement>`, qui reste intacte jusqu'à ING-014) défait le lancement par la `source` écrite dans ses alias. Repointe vers les ingrédients
 * d'origine, efface les alias du lancement, LAISSE les copies ; ce qui n'est pas de ce lancement n'est pas touché.
 */
class SeedingUndoByTheMigrationCommandTest extends DatabaseTestCase
{
    use SeedingMeasures;

    private SeedingDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheMigrationUndoRepointsToTheOriginsKeepsTheCopiesAndErasesTheAliasesOfTheRun(): void
    {
        // Given : le compte vide est amorcé par un lancement de rattrapage ; le compte neuf par un autre lancement
        $seed = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));
        $this->assertSame(Command::SUCCESS, $seed->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'rattrapage-annule', '--user' => [$this->dataset->adoption->emptyId]]));
        $this->assertSame(Command::SUCCESS, $seed->execute(['--apply' => true, '--source' => 'rattrapage-garde', '--user' => [$this->dataset->freshId]]));
        $emptyId = $this->dataset->adoption->emptyId;
        $contentBefore = $this->contentOf($emptyId);
        $copiesBefore = (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$emptyId]);
        $this->assertCount(8, $this->copiesOf($emptyId));

        // When
        $undo = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $status = $undo->execute(['--undo' => 'rattrapage-annule', '--apply' => true, '--accept-sent' => true, '--user' => [$emptyId]]);

        // Then : les lignes et les choix du drive pointent de nouveau vers les ingrédients d'origine, les alias du lancement sont effacés, les copies restent
        $this->assertSame(Command::SUCCESS, $status, $undo->getDisplay());
        $this->assertSame([], $this->copiesOf($emptyId));
        $this->assertSame($copiesBefore, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$emptyId]));
        $this->assertSame($contentBefore, $this->contentOf($emptyId), 'le contenu vu par les noms est le même');
        $this->assertSame(['beurre', 'couche-taille-4', 'farine'], $this->dataset->adoption->connection->fetchFirstColumn('SELECT ingredient FROM (SELECT ingredient, shopping_list FROM shopping_list_row UNION SELECT ingredient, shopping_list FROM shopping_list_ingredient) x WHERE shopping_list IN (SELECT id FROM shopping_list WHERE owner = ?) GROUP BY ingredient ORDER BY ingredient', [$emptyId]));
        // L'autre lancement n'a pas bougé
        $this->assertCount(8, $this->copiesOf($this->dataset->freshId));
    }
}

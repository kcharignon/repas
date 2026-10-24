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
 * Les filets du rattrapage de l'amorçage (ING-008) : une anomalie en cours de route annule UN compte seulement (transaction par compte, sans
 * demi-copie, sans alias seul), la commande s'arrête au premier échec ou continue avec `--keep-going`, et le message ne contient aucun
 * identifiant. Les fautes sont injectées par des déclencheurs PostgreSQL créés DANS la transaction du test (annulée avec lui).
 */
class SeedIngredientsFailureTest extends DatabaseTestCase
{
    use SeedingMeasures;

    private SeedingDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));
    }

    public function testAFingerprintThatDivergesRollsBackThatAccountOnlyAndStopsTheRun(): void
    {
        // Given : une faute qui change la QUANTITÉ d'une ligne de recette du gros compte quand son ingrédient est repointé, donc une empreinte qui
        // diverge ; le gros compte est le premier traité (identifiants fixes, ordre des identifiants)
        $this->injectQuantityCorruptionForTheBigAccount();
        $bigBefore = $this->dataset->adoption->hashOfUser($this->dataset->adoption->bigId);
        $copierBefore = $this->dataset->adoption->hashOfUser($this->dataset->adoption->copierId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $output = $this->display();

        // Then : arrêt au premier échec, le gros compte n'a rien reçu (ni copie, ni alias, ni ligne, ni version), les suivants non plus
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit', $output);
        $this->assertStringContainsString('Les empreintes d\'avant et d\'après ne correspondent pas', $output);
        $this->assertStringContainsString('recipe_row : la somme des quantités a changé', $output);
        $this->assertStringContainsString('Arrêt au premier échec', $output);
        $this->assertSame($bigBefore, $this->dataset->adoption->hashOfUser($this->dataset->adoption->bigId));
        $this->assertSame($copierBefore, $this->dataset->adoption->hashOfUser($this->dataset->adoption->copierId));
        $this->assertSame(0, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
        $this->assertSame(0, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
    }

    public function testKeepGoingSeedsTheOtherAccountsWhenOneIsRolledBack(): void
    {
        // Given
        $this->injectQuantityCorruptionForTheBigAccount();
        $bigBefore = $this->dataset->adoption->hashOfUser($this->dataset->adoption->bigId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--keep-going' => true]);

        // Then : le gros compte est annulé seul ; les autres comptes sont servis en entier
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($bigBefore, $this->dataset->adoption->hashOfUser($this->dataset->adoption->bigId));
        $this->assertSame(0, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$this->dataset->adoption->bigId]));
        foreach ([$this->dataset->adoption->singleId, $this->dataset->adoption->emptyId, $this->dataset->freshId] as $userId) {
            $this->assertCount(8, $this->copiesOf($userId));
        }
    }

    public function testAnErrorInTheMiddleOfAnAccountLeavesNoHalfCopyAndTheMessageHasNoIdentifier(): void
    {
        // Given : une erreur au moment de copier les conversions, après les alias et les copies d'ingrédients du gros compte
        $connection = $this->dataset->adoption->connection;
        $bigId = $this->dataset->adoption->bigId;
        $connection->executeStatement(
            "CREATE FUNCTION test_boom() RETURNS trigger AS \$\$ BEGIN IF NEW.ingredient LIKE '%" . $bigId . "' THEN RAISE EXCEPTION 'boom sur %', NEW.ingredient; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $connection->executeStatement('CREATE TRIGGER test_boom BEFORE INSERT ON conversion FOR EACH ROW EXECUTE FUNCTION test_boom()');
        $bigBefore = $this->dataset->adoption->hashOfUser($bigId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--keep-going' => true]);
        $output = $this->display();

        // Then : aucune copie à moitié, les autres sont servis, le message ne contient aucun identifiant
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($bigBefore, $this->dataset->adoption->hashOfUser($bigId));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$bigId]));
        $this->assertCount(9, $this->copiesOf($this->dataset->adoption->copierId));
        $this->assertStringContainsString('boom sur', $output);
        $this->assertStringContainsString('<id>', $output);
        $this->assertStringNotContainsString($bigId, $output);
    }

    public function testAFailureInTheMiddleLeavesAnAccountThatACommandRerunServesCompletely(): void
    {
        // Given : le premier lancement échoue pour le gros compte, la faute est ensuite levée
        $this->injectQuantityCorruptionForTheBigAccount();
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--keep-going' => true]);
        $this->dataset->adoption->connection->executeStatement('DROP TRIGGER test_corrupt ON recipe_row');
        $contentBefore = $this->contentOf($this->dataset->adoption->bigId);

        // When : on relance (sans danger, les comptes déjà servis sont ignorés)
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : le gros compte est servi en entier, avec le même contenu qu'avant
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertCount(12, $this->copiesOf($this->dataset->adoption->bigId));
        $this->assertSame($contentBefore, $this->contentOf($this->dataset->adoption->bigId));
    }

    private function injectQuantityCorruptionForTheBigAccount(): void
    {
        $this->dataset->adoption->connection->executeStatement(
            "CREATE FUNCTION test_corrupt() RETURNS trigger AS \$\$ BEGIN IF OLD.ingredient IS DISTINCT FROM NEW.ingredient"
            . " AND EXISTS (SELECT 1 FROM recipe WHERE id = NEW.recipe AND author = '" . $this->dataset->adoption->bigId . "') THEN NEW.quantity := NEW.quantity + 1; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $this->dataset->adoption->connection->executeStatement('CREATE TRIGGER test_corrupt BEFORE UPDATE ON recipe_row FOR EACH ROW EXECUTE FUNCTION test_corrupt()');
    }

    /**
     * La sortie de la commande, espaces et retours à la ligne ramenés à une espace : un bloc d'erreur est coupé à la largeur du terminal.
     */
    private function display(): string
    {
        return preg_replace('/\\s+/', ' ', $this->tester->getDisplay());
    }
}

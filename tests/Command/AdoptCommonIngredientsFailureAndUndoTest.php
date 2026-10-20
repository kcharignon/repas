<?php

namespace Repas\Tests\Command;


use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les deux filets de la migration d'adoption (ING-003) : une anomalie en cours de route annule UN compte seulement (transaction par
 * compte, sans demi-copie), et le retour arrière des données d'un lancement (`--undo`) repointe vers les communs sans rien supprimer.
 * Les fautes sont injectées par des déclencheurs PostgreSQL créés DANS la transaction du test (annulée avec lui).
 */
class AdoptCommonIngredientsFailureAndUndoTest extends DatabaseTestCase
{
    private AdoptionDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
    }

    public function testAFingerprintThatDivergesRollsBackThatAccountOnlyAndStopsTheRun(): void
    {
        // Given : une faute qui change la QUANTITÉ d'une ligne de recette du gros compte quand son ingrédient est repointé, donc une empreinte qui
        // diverge ; le gros compte est le premier traité (identifiants fixes)
        $this->injectQuantityCorruptionForTheBigAccount();
        $bigBefore = $this->dataset->hashOfUser($this->dataset->bigId);
        $copierBefore = $this->dataset->hashOfUser($this->dataset->copierId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $output = $this->display();

        // Then : arrêt au premier échec, le gros compte n'a rien reçu (ni copie, ni alias, ni ligne, ni version), les suivants non plus
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit', $output);
        $this->assertStringContainsString('Les empreintes d\'avant et d\'après ne correspondent pas', $output);
        $this->assertStringContainsString('recipe_row : la somme des quantités a changé', $output);
        $this->assertStringContainsString('Arrêt au premier échec', $output);
        $this->assertSame($bigBefore, $this->dataset->hashOfUser($this->dataset->bigId));
        $this->assertSame($copierBefore, $this->dataset->hashOfUser($this->dataset->copierId));
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
    }

    public function testKeepGoingMigratesTheOtherAccountsWhenOneIsRolledBack(): void
    {
        // Given
        $this->injectQuantityCorruptionForTheBigAccount();
        $bigBefore = $this->dataset->hashOfUser($this->dataset->bigId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--keep-going' => true]);

        // Then : le gros compte est annulé seul ; les autres comptes concernés sont migrés en entier
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($bigBefore, $this->dataset->hashOfUser($this->dataset->bigId));
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$this->dataset->bigId]));
        foreach ([$this->dataset->copierId, $this->dataset->singleId, $this->dataset->disabledId] as $userId) {
            $this->assertSame(12, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));
            $this->assertSame(12, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$userId]));
        }
    }

    public function testAnErrorInTheMiddleOfAnAccountLeavesNoHalfCopyAndTheMessageHasNoIdentifier(): void
    {
        // Given : une erreur au moment de copier les conversions, après les alias et les copies d'ingrédients du gros compte
        $this->dataset->connection->executeStatement(
            "CREATE FUNCTION test_boom() RETURNS trigger AS \$\$ BEGIN IF NEW.ingredient LIKE '%" . $this->dataset->bigId . "' THEN RAISE EXCEPTION 'boom sur %', NEW.ingredient; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $this->dataset->connection->executeStatement('CREATE TRIGGER test_boom BEFORE INSERT ON conversion FOR EACH ROW EXECUTE FUNCTION test_boom()');
        $bigBefore = $this->dataset->hashOfUser($this->dataset->bigId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--keep-going' => true]);
        $output = $this->display();

        // Then : aucune copie à moitié, les autres sont migrés, le message ne contient aucun identifiant
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($bigBefore, $this->dataset->hashOfUser($this->dataset->bigId));
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$this->dataset->bigId]));
        $this->assertSame(12, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$this->dataset->copierId]));
        $this->assertStringContainsString('boom sur', $output);
        $this->assertStringContainsString('<id>', $output);
        $this->assertStringNotContainsString($this->dataset->bigId, $output);
    }

    public function testUndoRepointsToTheCommonsKeepsTheCopiesAndLeavesAModifiedCopyAlone(): void
    {
        // Given : un lancement, puis la personne a renommé la copie du sel
        $dataset = $this->dataset;
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'lancement-a']);
        $saltCopy = $dataset->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'sel'", [$dataset->bigId]);
        $dataset->connection->executeStatement("UPDATE ingredient SET name = 'Sel de Guérande' WHERE slug = ?", [$saltCopy]);
        $copiesBefore = (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL');
        $conversionsBefore = (int) $dataset->connection->fetchOne('SELECT count(*) FROM conversion');
        $commonsBefore = $dataset->connection->fetchOne("SELECT md5(string_agg(t::text, ',' ORDER BY slug)) FROM ingredient t WHERE creator IS NULL");
        $versionsBefore = $dataset->connection->fetchAllKeyValue('SELECT id, version FROM shopping_list WHERE owner = ?', [$dataset->bigId]);
        $bigRowsOnTheSaltBefore = (int) $dataset->connection->fetchOne('SELECT count(*) FROM recipe_row WHERE ingredient = ?', [$saltCopy]);

        // When : sans --apply la commande refuse ; avec, le retour arrière s'exécute
        $refused = $this->tester->execute(['--undo' => 'lancement-a']);
        $status = $this->tester->execute(['--undo' => 'lancement-a', '--apply' => true, '--accept-sent' => true]);
        $output = $this->display();

        // Then
        $this->assertSame(Command::INVALID, $refused);
        $this->assertSame(Command::SUCCESS, $status, $output);
        // Les copies et leurs conversions restent, les communs n'ont pas bougé
        $this->assertSame($copiesBefore, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
        $this->assertSame($conversionsBefore, (int) $dataset->connection->fetchOne('SELECT count(*) FROM conversion'));
        $this->assertSame($commonsBefore, $dataset->connection->fetchOne("SELECT md5(string_agg(t::text, ',' ORDER BY slug)) FROM ingredient t WHERE creator IS NULL"));
        // Le gros compte : ses lignes sont revenues sur les communs, sauf celles de la copie modifiée (son travail)
        $this->assertSame(0, (int) $dataset->connection->fetchOne(
            'SELECT count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE r.author = ? AND i.adopted_from IS NOT NULL AND i.slug <> ?',
            [$dataset->bigId, $saltCopy],
        ));
        $this->assertSame($bigRowsOnTheSaltBefore, (int) $dataset->connection->fetchOne('SELECT count(*) FROM recipe_row WHERE ingredient = ?', [$saltCopy]));
        $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug WHERE c.user_id = ? AND i.adopted_from IS NOT NULL AND i.slug <> ?', [$dataset->bigId, $saltCopy]));
        // Les alias du lancement sont effacés, sauf celui de la copie modifiée
        $this->assertSame(['sel'], $dataset->connection->fetchFirstColumn("SELECT alias_slug FROM ingredient_alias WHERE user_id = ? AND source = 'lancement-a'", [$dataset->bigId]));
        // Les listes touchées montent encore d'une version, la liste envoyée garde statut et date d'envoi
        foreach ($dataset->connection->fetchAllKeyValue('SELECT id, version FROM shopping_list WHERE owner = ?', [$dataset->bigId]) as $id => $version) {
            $this->assertSame($versionsBefore[$id] + 1, $version);
        }
        $this->assertSame(['SENT', '2026-10-10 12:00:00'], $dataset->connection->fetchNumeric('SELECT status, sent_at FROM shopping_list WHERE id = ?', [$dataset->bigLists['SENT']]));
        $this->assertStringContainsString('1 copie(s) modifiée(s) depuis laissée(s) telle(s) quelle(s)', $output);
        $this->assertStringContainsString('Les copies restent.', $output);
    }

    public function testUndoNeedsAcceptSentWhileASentListIsConcernedAndAnotherRunNameDoesNothing(): void
    {
        // Given
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'lancement-a']);
        $after = $this->dataset->tableHashes();

        // When
        $other = $this->tester->execute(['--undo' => 'un-autre-lancement', '--apply' => true, '--accept-sent' => true]);
        $withoutAccept = $this->tester->execute(['--undo' => 'lancement-a', '--apply' => true]);

        // Then : un autre nom de lancement ne défait rien ; sans --accept-sent, le gros compte (liste envoyée) est annulé, pas les autres
        $this->assertSame(Command::SUCCESS, $other);
        $this->assertSame(Command::FAILURE, $withoutAccept);
        $this->assertStringContainsString('déjà envoyée(s) au drive seraient modifiées', $this->display());
        $this->assertSame($after, $this->dataset->tableHashes());
    }

    public function testAnApplyAfterAnUndoDesignatesTheCopiesThatAreStillThereInsteadOfDoublingThem(): void
    {
        // Given : lancement, retour arrière complet, puis un nouveau lancement
        $dataset = $this->dataset;
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'lancement-a']);
        $this->tester->execute(['--undo' => 'lancement-a', '--apply' => true, '--accept-sent' => true]);
        $copiesAfterUndo = (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL');
        $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
        $before = array_map($dataset->hashOfUser(...), [$dataset->adminId, $dataset->emptyId]);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'lancement-b']);
        $output = $this->display();

        // Then : aucun doublon, les alias désignent les mêmes copies, tout est de nouveau repointé
        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertSame($copiesAfterUndo, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
        $this->assertSame(12 * 4, (int) $dataset->connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE source = 'lancement-b'"));
        $this->assertSame($before, array_map($dataset->hashOfUser(...), [$dataset->adminId, $dataset->emptyId]));
        $this->assertStringContainsString('0 copie(s) créée(s) (dont 0 à slug suffixé) et 12 déjà présente(s) désignée(s)', $output);
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true]));
    }

    private function injectQuantityCorruptionForTheBigAccount(): void
    {
        $this->dataset->connection->executeStatement(
            "CREATE FUNCTION test_corrupt() RETURNS trigger AS \$\$ BEGIN IF OLD.ingredient IS DISTINCT FROM NEW.ingredient"
            . " AND EXISTS (SELECT 1 FROM recipe WHERE id = NEW.recipe AND author = '" . $this->dataset->bigId . "') THEN NEW.quantity := NEW.quantity + 1; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $this->dataset->connection->executeStatement('CREATE TRIGGER test_corrupt BEFORE UPDATE ON recipe_row FOR EACH ROW EXECUTE FUNCTION test_corrupt()');
    }

    private function display(): string
    {
        return preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }
}

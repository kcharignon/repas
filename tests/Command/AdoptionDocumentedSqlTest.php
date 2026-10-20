<?php

namespace Repas\Tests\Command;


use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le SQL que `docs/ingredients/migration.md` met entre les mains de l'humain (retour arrière sans la commande, contrôles de fin) est
 * EXÉCUTÉ ici, tel qu'il est écrit dans le document : le texte testé est le texte publié. Le retour arrière en SQL donne le même état que
 * `--undo` ; les contrôles de fin donnent les valeurs que le document annonce.
 */
class AdoptionDocumentedSqlTest extends DatabaseTestCase
{
    private const string RUN = 'migration-2026-10-21';

    private AdoptionDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
    }

    public function testTheDocumentedUndoSqlGivesTheSameStateAsTheUndoOption(): void
    {
        // Given : un lancement complet
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => self::RUN]));
        $connection = $this->dataset->connection;
        $connection->executeStatement('SAVEPOINT apres_migration');

        // When : voie 1, la commande
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--undo' => self::RUN, '--apply' => true, '--accept-sent' => true]));
        $viaCommand = $this->dataset->tableHashes();
        $connection->executeStatement('ROLLBACK TO SAVEPOINT apres_migration');
        $this->assertNotSame($viaCommand, $this->dataset->tableHashes());

        // When : voie 2, le SQL du document, exécuté tel quel (en une transaction : celle du test)
        $connection->executeStatement($this->documentedSql('retour-arriere-sql', ':\'run\'', $connection->quote(self::RUN)));
        $viaSql = $this->dataset->tableHashes();

        // Then : le même état, table par table ; les copies et leurs conversions sont restées, les communs aussi
        $this->assertSame($viaCommand, $viaSql);
        $this->assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE source = ?", [self::RUN]));
        $this->assertSame(48, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
    }

    public function testTheDocumentedFinalChecksGiveWhatTheDocumentSays(): void
    {
        // Given : l'état d'avant (la photographie globale), puis le lancement complet
        $statements = $this->documentedStatements('controles-sql');
        $this->assertCount(6, $statements);
        $connection = $this->dataset->connection;
        $before = $connection->fetchAssociative($statements[4]);
        $this->assertSame(0, (int) $before['alias']);
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => self::RUN]));

        // When / Then : 1. plus aucune ligne sur un commun chez les comptes migrés
        $this->assertSame([['recettes', 0], ['lignes de liste', 0], ['ingredients de liste', 0], ['choix du drive', 0]], array_map(static fn(array $row): array => [$row[0], (int) $row[1]], $connection->fetchAllNumeric($statements[0])));
        // 2. aucun alias invalide
        $this->assertSame(0, (int) $connection->fetchOne($statements[1]));
        // 3. aucun alias hors périmètre
        $this->assertSame(0, (int) $connection->fetchOne($statements[2]));
        // 4. quatre comptes migrés, 12 alias chacun, 12 communs
        $this->assertSame([4, 12, 12, 12], array_map('intval', $connection->fetchNumeric($statements[3])));
        // 5. la photographie globale est celle d'avant, sauf les alias
        $after = $connection->fetchAssociative($statements[4]);
        $this->assertSame(48, (int) $after['alias']);
        unset($before['alias'], $after['alias']);
        $this->assertSame($before, $after);
        // 6. aucune référence orpheline
        $this->assertSame([0, 0, 0, 0], array_map(static fn(array $row): int => (int) $row[1], $connection->fetchAllNumeric($statements[5])));
    }

    public function testTheFinalChecksSeeAMistake(): void
    {
        // Given : un lancement complet, puis une ligne remise sur un commun et un alias dont la copie a disparu
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => self::RUN]);
        $connection = $this->dataset->connection;
        $connection->executeStatement("UPDATE recipe_row SET ingredient = 'farine' WHERE id IN (SELECT x.id FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE r.author = ? LIMIT 1)", [$this->dataset->bigId]);
        $connection->executeStatement("DELETE FROM ingredient WHERE slug = ?", ['sel' . $this->dataset->singleId]);
        $statements = $this->documentedStatements('controles-sql');

        // Then
        $this->assertSame(1, (int) $connection->fetchAllNumeric($statements[0])[0][1]);
        $this->assertSame(1, (int) $connection->fetchOne($statements[1]));
    }

    /**
     * Le bloc SQL qui suit le marqueur `<!-- $marker -->` du document, avec `$placeholder` remplacé par `$value`.
     */
    private function documentedSql(string $marker, string $placeholder = '', string $value = ''): string
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/ingredients/migration.md');
        $this->assertSame(1, preg_match('/<!-- ' . preg_quote($marker, '/') . ' -->\R```sql\R(.*?)\R```/s', $document, $matches), "le marqueur $marker est dans le document");

        return $placeholder === '' ? $matches[1] : str_replace($placeholder, $value, $matches[1]);
    }

    /**
     * @return list<string> les requêtes du bloc, sans les commentaires
     */
    private function documentedStatements(string $marker): array
    {
        $sql = preg_replace('/^--.*$/m', '', $this->documentedSql($marker));

        return array_values(array_filter(array_map('trim', explode(';', (string) $sql)), static fn(string $statement): bool => $statement !== ''));
    }
}

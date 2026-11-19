<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Interface\ShoppingListArchiveRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Les requêtes de `docs/listes/journal.md` (ADR 0041) sont rejouées sur la vraie base : une requête de la documentation qui ne tourne plus (colonne
 * renommée, table changée) fait échouer ce test au lieu de tromper celui qui l'utilise en production.
 */
class DocsListJournalQueriesTest extends DatabaseTestCase
{
    private Connection $connection;
    private string $listId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
        $container = static::getContainer();
        $persister = new DataPersister($container);
        $owner = new UserBuilder()->build();
        $persister->saveUser($owner);
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($owner)->build();
        $persister->saveIngredient($milk);
        $persister->saveIngredient($egg);

        // Une liste envoyée de lait et d'œufs : le lait est au panier, les œufs sont refusés par le drive
        $list = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::SENT)->withSentAt(new DateTimeImmutable('2026-11-19 09:00:00'))->addIngredient($milk)->addIngredient($egg)->build();
        $container->get(ShoppingListRepository::class)->save($list);
        $this->listId = $list->getId();
        $container->get(ShoppingListRowRepository::class)->recordDrivePassage($this->listId, [
            new DriveLineAttempt($milk->getSlug(), DriveLineOutcome::IN_CART),
            new DriveLineAttempt($egg->getSlug(), DriveLineOutcome::NOT_IN_CART),
        ], new DateTimeImmutable('2026-11-19 10:00:00'));
        $this->entityManager->clear();

        // Et son archive, comme si elle avait été supprimée
        $container->get(ShoppingListArchiveRepository::class)->archive('archive-1', $this->listId, ShoppingListArchiveCause::DELETED_BY_USER, new DateTimeImmutable('2026-11-19 11:00:00'));
    }

    public function testEveryQueryOfTheDocumentationRuns(): void
    {
        // Given : les blocs `sql` de la documentation, sans l'identifiant qui reste à remplir
        $queries = $this->queries();
        $this->assertGreaterThanOrEqual(9, count($queries));

        // When / Then : chacune s'exécute sans erreur
        foreach ($queries as $query) {
            $rows = $this->connection->fetchAllAssociative(str_replace(['<identifiant de la liste>', '<identifiant du compte>'], [$this->listId, 'aucun'], $query));
            $this->assertIsArray($rows);
        }
    }

    public function testTheQueriesAnswerWhatTheyPromise(): void
    {
        $queries = $this->queries();
        $find = static fn(string $start): string => array_values(array_filter($queries, static fn(string $query): bool => str_contains($query, $start)))[0];

        // L'article refusé par le drive et toujours à acheter
        $refused = $this->connection->fetchAllAssociative($find("r.drive_outcome = 'NOT_IN_CART' AND r.checked = false"));
        $this->assertCount(1, $refused);
        $this->assertSame($this->listId, $refused[0]['liste']);

        // Les issues comptées : la liste en cours et son archive ensemble, deux fois chacune
        $counts = array_column($this->connection->fetchAllAssociative($find('GROUP BY issue')), 'lignes', 'issue');
        $this->assertSame(['IN_CART' => 2, 'NOT_IN_CART' => 2], array_map('intval', $counts));

        // Dans l'archive, la ligne refusée est retrouvée avec son nom
        $archived = $this->connection->fetchAllAssociative($find("WHERE line->>'drive_outcome' = 'NOT_IN_CART'\nORDER BY a.archived_at DESC"));
        $this->assertCount(1, $archived);
        $this->assertSame(['NOT_IN_CART', 'false'], [$archived[0]['issue'], $archived[0]['cochee']]);

        // La liste envoyée dont le drive a coché une ligne n'est PAS dans « aucune ligne cochée par le drive »
        $this->assertSame([], $this->connection->fetchAllAssociative($find("NOT EXISTS (SELECT 1 FROM shopping_list_row r WHERE r.shopping_list = l.id AND r.checked_by = 'DRIVE')")));
    }

    public function testTheServerReportRunsAndOnlyCounts(): void
    {
        // Given : la requête de la demande `rapport journal` du programme en lecture seule du serveur (`repas-ops`), lue dans le script qui l'installe
        $script = file_get_contents(dirname(__DIR__, 3) . '/.claude/skills/repas-ops/references/installer-acces-lecture-seule.sh');
        $this->assertNotFalse($script);
        $this->assertSame(1, preg_match('/journal\) SQL="([^"]*)" ;;/', $script, $match));

        // When
        $rows = $this->connection->fetchAllAssociative($match[1]);

        // Then : des rubriques et des décomptes, la liste et son archive comptées chacune
        $report = array_column($rows, 'valeur', 'rubrique');
        $this->assertSame(['rubrique', 'valeur'], array_keys($rows[0]));
        $this->assertSame('2', $report['lignes de listes en cours ou envoyees']);
        $this->assertSame('1', $report['lignes cochees, origine DRIVE']);
        $this->assertSame('1', $report['issue du dernier passage au drive : IN_CART']);
        $this->assertSame('1', $report['issue du dernier passage au drive : NOT_IN_CART']);
        $this->assertSame('1', $report['refusees par le drive et encore a acheter (listes non terminees)']);
        $this->assertSame('1', $report['archives, cause DELETED_BY_USER']);
        $this->assertSame('2', $report['archives : lignes au total']);
        $this->assertSame('1', $report['archives qui portent une ligne refusee par le drive']);

        // Ni identifiant, ni nom, ni adresse : le rapport ne sort que ses propres libellés et des nombres ou des dates
        $text = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->listId, $text);
        $this->assertStringNotContainsString('@', $text);
    }

    /**
     * @return list<string> les requêtes de la documentation, sans le point-virgule final
     */
    private function queries(): array
    {
        $doc = file_get_contents(dirname(__DIR__, 3) . '/docs/listes/journal.md');
        $this->assertNotFalse($doc);
        preg_match_all('/```sql\n(.*?)```/s', $doc, $matches);

        return array_map(static fn(string $query): string => rtrim(trim($query), ';'), $matches[1]);
    }
}

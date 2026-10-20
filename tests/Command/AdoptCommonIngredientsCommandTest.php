<?php

namespace Repas\Tests\Command;


use Repas\Command\AdoptCommonIngredientsCommand;
use Repas\Repas\Infrastructure\Loader\IngredientAdoptionReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La migration d'adoption des communs (ING-003) de bout en bout : la commande de console, les vrais bus, la vraie base, sur un jeu de
 * données qui reproduit la production (`AdoptionDataset`). Chaque propriété exigée par le ticket et par `docs/ingredients/migration.md`
 * est prouvée ici, avec des comptages et des sommes de contrôle calculés INDÉPENDAMMENT du code de production.
 */
class AdoptCommonIngredientsCommandTest extends DatabaseTestCase
{
    private AdoptionDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
    }

    public function testTheDryRunWritesNothingAndReportsEverything(): void
    {
        // Given
        $before = $this->dataset->tableHashes();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : sans option (à blanc) puis --check
        $dryRun = $this->tester->execute([]);
        $dryRunOutput = $this->display();
        $check = $this->tester->execute(['--check' => true]);

        // Then : aucune ligne de aucune table n'a bougé, et aucune requête d'écriture ni verrou n'a même été envoyé
        $this->assertSame($before, $this->dataset->tableHashes());
        $this->assertGreaterThan(20, $counter->count());
        $this->assertSame([], array_values(array_filter(
            $counter->statements(),
            static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|\\b(INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+\\w+\\s+SET)\\b|pg_advisory/i', $sql) === 1,
        )));
        $this->assertSame(Command::SUCCESS, $dryRun);
        $this->assertSame(Command::FAILURE, $check, '--check dit qu\'il reste à faire');
        $this->assertStringContainsString('à blanc (lecture seule)', $dryRunOutput);
        $this->assertStringContainsString("À blanc : rien n'a été modifié.", $dryRunOutput);
        $this->assertStringContainsString('Catalogue : 12 communs (jamais modifiés), 3 conversions propres', $dryRunOutput);
        $this->assertStringContainsString('Comptes : 6 dont 1 administrateur(s) et 4 retenu(s)', $dryRunOutput);
        $this->assertStringContainsString('ignoré, administrateur', $dryRunOutput);
        $this->assertStringContainsString('ignoré, ni ingrédient personnel ni recette', $dryRunOutput);
        $this->assertStringContainsString('dont 1 slug(s) à suffixer (Tomate)', $dryRunOutput, 'la collision de slug est détaillée par le nom du COMMUN');
        $this->assertStringContainsString('12 commun(s) (déjà adoptés : 0)', $dryRunOutput);
        $this->assertStringContainsString('dont 1 envoyée(s) au drive', $dryRunOutput);
        $this->assertStringContainsString('exigera --accept-sent', $dryRunOutput);
        $this->assertMatchesRegularExpression('/empreinte du contenu \(identique avant et après\) : [0-9a-f]{12}/', $dryRunOutput);
    }

    public function testTheOutputNeverContainsAnEmailAnAccountIdentifierOrAPersonalIngredientName(): void
    {
        // When : le rapport, une écriture complète et son échec simulé n'affichent rien de personnel
        $outputs = [];
        $this->tester->execute([]);
        $outputs[] = $this->display();
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $outputs[] = $this->display();
        $this->tester->execute(['--check' => true]);
        $outputs[] = $this->display();

        // Then
        $output = implode("\n", $outputs);
        $secrets = [
            ...$this->dataset->connection->fetchFirstColumn('SELECT email FROM "user"'),
            ...$this->dataset->connection->fetchFirstColumn('SELECT id FROM "user"'),
            // Les noms des ingrédients PERSONNELS ; celui qui est aussi le nom d'un commun (« Tomate ») est un nom public du catalogue
            ...$this->dataset->connection->fetchFirstColumn('SELECT name FROM ingredient WHERE creator IS NOT NULL AND adopted_from IS NULL AND name NOT IN (SELECT name FROM ingredient WHERE creator IS NULL)'),
            'example.com',
        ];
        $this->assertGreaterThan(8, count($secrets));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function testApplyIsRefusedWithoutAcceptSentWhileASentListIsConcerned(): void
    {
        // Given
        $before = $this->dataset->tableHashes();

        // When
        $status = $this->tester->execute(['--apply' => true]);

        // Then : rien n'est écrit, la commande dit pourquoi et comment
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($before, $this->dataset->tableHashes());
        $this->assertStringContainsString('1 liste(s) déjà envoyée(s) au drive seraient repointées', $this->display());
        $this->assertStringContainsString('--accept-sent', $this->display());
    }

    public function testApplyAdoptsEveryCommonForEachConcernedAccountAndTouchesNoOneElse(): void
    {
        // Given : l'état d'avant, mesuré à part
        $dataset = $this->dataset;
        $concerned = [$dataset->bigId, $dataset->copierId, $dataset->singleId, $dataset->disabledId];
        $untouched = [$dataset->adminId, $dataset->emptyId];
        $untouchedHashes = array_map($dataset->hashOfUser(...), $untouched);
        $commonsHash = $this->commonsHash();
        $personalTomatoBefore = $this->rowOf('ingredient', 'slug', 'tomate' . $dataset->bigId);
        $contentBefore = array_map($this->contentOf(...), $concerned);
        $driveBefore = array_map($this->driveContentOf(...), $concerned);
        $versionsBefore = $this->versionsOf($dataset->bigId);
        $statisticsBefore = array_map(fn(string $userId): string => (string) $dataset->connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]), [...$concerned, ...$untouched]);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : succès, « rien à faire » en fin de lancement
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertStringContainsString('Rien à faire : 0 à adopter, 0 à repointer, empreintes identiques à celles d\'avant.', $this->display());

        // Les comptes hors périmètre : rien, ni copie, ni alias, ni ligne, ni version
        foreach ($untouched as $index => $userId) {
            $this->assertSame($untouchedHashes[$index], $dataset->hashOfUser($userId));
            $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));
            $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$userId]));
        }
        // Les communs : intacts (aucun supprimé, aucun modifié, leurs conversions non plus)
        $this->assertSame($commonsHash, $this->commonsHash());
        $this->assertSame(12, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));

        foreach ($concerned as $index => $userId) {
            // Une copie par commun, conforme, avec un alias valide
            $this->assertSame(12, (int) $dataset->connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption' AND source LIKE 'migration-%'", [$userId]));
            $this->assertSame(12, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$userId]));
            $this->assertSame([], $dataset->connection->fetchFirstColumn(
                'SELECT c.slug FROM ingredient c LEFT JOIN ingredient_alias a ON a.user_id = :u AND a.alias_slug = c.slug LEFT JOIN ingredient o ON o.slug = a.ingredient_slug'
                . ' WHERE c.creator IS NULL AND (o.slug IS NULL OR o.creator <> :u OR o.adopted_from <> c.slug OR o.name <> c.name OR o.image <> c.image'
                . '   OR o.department <> c.department OR o.default_cooking_unit <> c.default_cooking_unit OR o.default_purchase_unit <> c.default_purchase_unit'
                . '   OR o.compatible_units::text <> c.compatible_units::text)',
                ['u' => $userId],
            ));
            // Plus aucune ligne sur un commun (recettes, listes dont l'envoyée, choix du drive)
            $this->assertSame(['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0, 'drive_choice' => 0], $this->referencesToCommons($userId));
            // Aucune perte : même contenu (nom, rayon, quantité, unité) par recette et par liste, mêmes choix du drive et produits
            $this->assertSame($contentBefore[$index], $this->contentOf($userId));
            $this->assertSame($driveBefore[$index], $this->driveContentOf($userId));
        }

        // Les conversions PROPRES sont copiées (beurre 2, farine 1 par compte), les communes jamais
        $this->assertSame(4 * 3, (int) $dataset->connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.adopted_from IS NOT NULL'));
        $this->assertSame(1, (int) $dataset->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
        $this->assertSame(
            [[125.0, 'unite', 'gramme'], [15.5, 'cuillere-a-soupe', 'gramme']],
            array_map(static fn(array $row): array => [(float) $row[0], $row[1], $row[2]], $dataset->connection->fetchAllNumeric(
                'SELECT coefficient, start_unit, end_unit FROM conversion WHERE ingredient = ? ORDER BY start_unit DESC',
                [StringTool::slugify('Beurre' . $dataset->bigId)],
            )),
        );
        $this->assertSame(4 * 3, (int) $dataset->connection->fetchOne('SELECT count(DISTINCT id) FROM conversion c WHERE c.ingredient IN (SELECT slug FROM ingredient WHERE adopted_from IS NOT NULL)'));

        // La collision : « Tomate » du gros compte garde son slug et ses lignes, la copie du commun prend « -2 »
        $this->assertSame($personalTomatoBefore, $this->rowOf('ingredient', 'slug', 'tomate' . $dataset->bigId));
        $this->assertSame('tomate', $dataset->connection->fetchOne('SELECT adopted_from FROM ingredient WHERE slug = ?', [StringTool::slugify('Tomate-2' . $dataset->bigId)]));
        $this->assertSame(StringTool::slugify('Tomate-2' . $dataset->bigId), $dataset->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'tomate'", [$dataset->bigId]));
        // …et un nom qui finit par un symbole prend le slug que l'ingrédient créé à la main aurait
        $this->assertSame(StringTool::slugify('Couche (taille 4)' . $dataset->bigId), $dataset->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'couche-taille-4'", [$dataset->bigId]));

        // Les versions : +1 pour chaque recette et liste modifiée (toutes ici), les statuts et la date d'envoi inchangés
        $this->assertSame(array_map(static fn(int $version): int => $version + 1, $versionsBefore), $this->versionsOf($dataset->bigId));
        $this->assertSame(['SENT', '2026-10-10 12:00:00'], $dataset->connection->fetchNumeric('SELECT status, sent_at FROM shopping_list WHERE id = ?', [$dataset->bigLists['SENT']]));
        $this->assertSame(['ACTIVE', 'PAUSED', 'SENT', 'COMPLETED'], array_keys($dataset->bigLists));
        // Le choix du drive sur l'ingrédient PERSONNEL du gros compte n'a pas bougé
        $this->assertSame(1, (int) $dataset->connection->fetchOne('SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug = ?', [$dataset->bigId, StringTool::slugify('Praline' . $dataset->bigId)]));
        // Aucune statistique ne bouge : une adoption n'est pas une création (le compteur « ingrédients » du gros compte reste à 6)
        $this->assertSame($statisticsBefore, array_map(fn(string $userId): string => (string) $dataset->connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]), [...$concerned, ...$untouched]));
        $this->assertSame(6, json_decode($dataset->connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$dataset->bigId]), true)['ingredients']);
    }

    public function testASecondApplyWritesNothingAndCheckSaysThereIsNothingLeftToDo(): void
    {
        // Given : un premier lancement complet
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $afterFirst = $this->dataset->tableHashes();

        // When : le deuxième lancement n'a pas besoin de --accept-sent (rien ne serait modifié)
        $second = $this->tester->execute(['--apply' => true]);
        $secondOutput = $this->display();
        $check = $this->tester->execute(['--check' => true]);

        // Then : aucune ligne de aucune table n'a bougé (versions comprises), et --check le dit
        $this->assertSame(Command::SUCCESS, $second, $secondOutput);
        $this->assertSame($afterFirst, $this->dataset->tableHashes());
        $this->assertStringContainsString('0 à adopter, 0 à repointer, rien à faire.', $secondOutput);
        $this->assertSame(Command::SUCCESS, $check);
        $this->assertStringContainsString('Rien à faire : 0 à adopter, 0 à repointer, aucun alias invalide.', $this->display());
    }

    public function testACheckAfterTheMigrationReportsALineLeftOnACommonAndAnotherApplyRepointsIt(): void
    {
        // Given : le lancement a eu lieu, puis une écriture concurrente (un vieil onglet) a remis des lignes sur des communs
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $dataset = $this->dataset;
        $recipeId = $dataset->connection->fetchOne('SELECT id FROM recipe WHERE author = ? ORDER BY id LIMIT 1', [$dataset->bigId]);
        $dataset->connection->executeStatement("UPDATE recipe_row SET ingredient = 'farine' WHERE recipe = ? AND position = 0", [$recipeId]);
        $dataset->connection->executeStatement("UPDATE shopping_list_row SET ingredient = 'lait' WHERE shopping_list = ? AND ingredient LIKE 'beurre%'", [$dataset->bigLists['ACTIVE']]);

        // When
        $check = $this->tester->execute(['--check' => true]);
        $report = $this->display();
        $apply = $this->tester->execute(['--apply' => true]);

        // Then : --check la signale, un deuxième --apply la repointe, seule cette recette et cette liste montent d'une version
        $this->assertSame(Command::FAILURE, $check);
        $this->assertStringContainsString('1 ligne(s) dans 1 recette(s) ; 1 ligne(s) + 0 ingrédient(s) dans 1 liste(s)', $report);
        $this->assertSame(Command::SUCCESS, $apply, $this->display());
        $this->assertSame(['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0, 'drive_choice' => 0], $this->referencesToCommons($dataset->bigId));
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true]));
    }

    public function testUserAndExceptRestrictTheAccountsByShortReferenceOrFullIdentifier(): void
    {
        // Given
        $dataset = $this->dataset;
        $reference = IngredientAdoptionReportPostgreSQLLoader::referenceOf($dataset->singleId);

        // When : un seul compte, désigné par sa référence courte
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$reference]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        $this->assertSame(12, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$dataset->singleId]));
        foreach ([$dataset->bigId, $dataset->copierId, $dataset->disabledId, $dataset->adminId, $dataset->emptyId] as $other) {
            $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$other]));
        }
        $this->assertStringContainsString('hors de --user', $this->display());

        // When : tous sauf le gros compte et le désactivé (identifiants complets)
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--except' => [$dataset->bigId, $dataset->disabledId]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        $this->assertSame(12, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$dataset->copierId]));
        $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$dataset->bigId]));
        $this->assertStringContainsString('écarté par --except', $this->display());
    }

    public function testAnUnknownAccountIsRefusedBeforeAnyWrite(): void
    {
        // Given
        $before = $this->dataset->tableHashes();

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => ['ffffffff']]);

        // Then
        $this->assertSame(Command::INVALID, $status);
        $this->assertSame($before, $this->dataset->tableHashes());
        $this->assertStringContainsString('Aucun compte ne correspond à : ffffffff', $this->display());
    }

    public function testAnAdministratorNamedExplicitlyIsStillNotMigrated(): void
    {
        // Given
        $before = $this->dataset->hashOfUser($this->dataset->adminId);
        $reference = IngredientAdoptionReportPostgreSQLLoader::referenceOf($this->dataset->adminId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$reference]]);

        // Then : il garde les communs, rien n'est écrit
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame($before, $this->dataset->hashOfUser($this->dataset->adminId));
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
    }

    public function testAnOptionConflictAndAnInvalidRunNameAreRefused(): void
    {
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--check' => true]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => str_repeat('a', 65)]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => 'un lancement']));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => 'a', '--run' => 'b']));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--undo' => 'migration-2026-10-20']), '--undo écrit : il exige --apply');
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
    }

    public function testTheRunNameIsWrittenInEveryAlias(): void
    {
        // When : --source, et --run comme synonyme
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'lancement-essai', '--user' => [IngredientAdoptionReportPostgreSQLLoader::referenceOf($this->dataset->singleId)]]);
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--run' => 'lancement-bis', '--user' => [IngredientAdoptionReportPostgreSQLLoader::referenceOf($this->dataset->copierId)]]);

        // Then
        $this->assertSame([['lancement-essai', 12]], array_map(static fn(array $row): array => [$row[0], (int) $row[1]], $this->dataset->connection->fetchAllNumeric('SELECT source, count(*) FROM ingredient_alias WHERE user_id = ? GROUP BY source', [$this->dataset->singleId])));
        $this->assertSame([['lancement-bis', 12]], array_map(static fn(array $row): array => [$row[0], (int) $row[1]], $this->dataset->connection->fetchAllNumeric('SELECT source, count(*) FROM ingredient_alias WHERE user_id = ? GROUP BY source', [$this->dataset->copierId])));
    }

    public function testWritingIsRefusedOutsideTheAllowedEnvironments(): void
    {
        // Given : la même commande, dans un environnement qui n'est pas prod ni dev
        $container = static::getContainer();
        $command = new AdoptCommonIngredientsCommand(
            $container->get(QueryBusInterface::class),
            $container->get(CommandBusInterface::class),
            $container->get(\Psr\Clock\ClockInterface::class),
            'staging',
            ['prod', 'dev'],
        );
        $tester = new CommandTester($command);
        $before = $this->dataset->tableHashes();

        // When
        $apply = $tester->execute(['--apply' => true, '--accept-sent' => true]);
        $applyOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());
        $dryRun = $tester->execute([]);

        // Then : la lecture reste permise, l'écriture non
        $this->assertSame(Command::INVALID, $apply);
        $this->assertStringContainsString('Écriture refusée dans l\'environnement « staging »', $applyOutput);
        $this->assertSame(Command::SUCCESS, $dryRun);
        $this->assertSame($before, $this->dataset->tableHashes());
    }

    /**
     * La sortie de la commande, espaces et retours à la ligne ramenés à une espace : un bloc d'erreur est coupé à la largeur du terminal.
     */
    private function display(): string
    {
        return preg_replace('/\\s+/', ' ', $this->tester->getDisplay());
    }

    // --- Mesures indépendantes du code de production

    /**
     * @return array<string, int>
     */
    private function referencesToCommons(string $userId): array
    {
        $connection = $this->dataset->connection;
        $count = static fn(string $from): int => (int) $connection->fetchOne("SELECT count(*) $from AND i.creator IS NULL", ['u' => $userId]);

        return [
            'recipe_row' => $count('FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author = :u'),
            'shopping_list_row' => $count('FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u'),
            'shopping_list_ingredient' => $count('FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u'),
            'drive_choice' => $count('FROM drive_choice x JOIN ingredient i ON i.slug = x.ingredient_slug WHERE x.user_id = :u'),
        ];
    }

    /**
     * Le contenu de toutes les recettes et listes d'un compte, vu par le nom et le rayon des ingrédients (jamais le slug), trié.
     *
     * @return list<string>
     */
    private function contentOf(string $userId): array
    {
        $connection = $this->dataset->connection;
        $rows = [
            ...$connection->fetchFirstColumn("SELECT 'R|' || o.id || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit || '|' || x.position FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author = :u", ['u' => $userId]),
            ...$connection->fetchFirstColumn("SELECT 'L|' || o.id || '|' || o.status || '|' || coalesce(o.sent_at::text, '') || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit || '|' || x.checked::text FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u", ['u' => $userId]),
            ...$connection->fetchFirstColumn("SELECT 'I|' || o.id || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u", ['u' => $userId]),
        ];
        sort($rows);

        return $rows;
    }

    /**
     * Les choix du drive d'un compte avec leurs produits, vus par le nom de l'ingrédient (jamais le slug), triés.
     *
     * @return list<string>
     */
    private function driveContentOf(string $userId): array
    {
        $rows = $this->dataset->connection->fetchFirstColumn(
            "SELECT c.id || '|' || i.name || '|' || c.retailer || '|' || c.store_id || '|' || c.kind || '|' || coalesce(c.search_query, '') || '|' || c.updated_at::text || '|' ||"
            . " coalesce((SELECT string_agg(p.product_id || ':' || p.verdict || ':' || coalesce(p.rank::text, '') || ':' || coalesce(p.pack_size_value::text, '') || ':' || coalesce(p.pack_size_unit_slug, ''), ',' ORDER BY p.product_id) FROM drive_choice_product p WHERE p.choice_id = c.id), '')"
            . ' FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug WHERE c.user_id = :u',
            ['u' => $userId],
        );
        sort($rows);

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function versionsOf(string $userId): array
    {
        $connection = $this->dataset->connection;

        return [
            ...$connection->fetchAllKeyValue('SELECT id, version FROM recipe WHERE author = ? ORDER BY id', [$userId]),
            ...$connection->fetchAllKeyValue('SELECT id, version FROM shopping_list WHERE owner = ? ORDER BY id', [$userId]),
        ];
    }

    private function commonsHash(): string
    {
        $connection = $this->dataset->connection;

        return md5(
            implode("\n", $connection->fetchFirstColumn('SELECT t::text FROM ingredient t WHERE creator IS NULL ORDER BY slug'))
            . '--' . implode("\n", $connection->fetchFirstColumn('SELECT t::text FROM conversion t WHERE ingredient IS NULL OR ingredient IN (SELECT slug FROM ingredient WHERE creator IS NULL) ORDER BY id')),
        );
    }

    private function rowOf(string $table, string $column, string $value): string
    {
        return (string) $this->dataset->connection->fetchOne(sprintf('SELECT t::text FROM %s t WHERE %s = ?', $table, $column), [$value]);
    }
}

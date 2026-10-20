<?php

namespace Repas\Tests\Command;


use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le SQL que `docs/ingredients/sans-commun.md` § 5 met entre les mains de l'humain (les contrôles « avant » et « après » la passation, le retour
 * arrière des données) est EXÉCUTÉ ici, tel qu'il est écrit dans le document, sur le jeu de données de type production : le texte testé est le
 * texte publié. Les contrôles donnent les valeurs que le document annonce, voient une erreur, et le retour arrière donne l'état d'avant la
 * passation, après quoi une nouvelle passation rétablit l'état (la commande est idempotente).
 */
class CatalogHandoverDocumentedSqlTest extends DatabaseTestCase
{
    private HandoverDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $this->dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()), migratedAccounts: 3);
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
    }

    public function testTheSixReadOnlySurveysOfTheOperationsGuideRunAndGiveTheNumbersOfTheState(): void
    {
        // Given : le bloc R1 à R6 du § 14, exécuté tel qu'il est écrit (l'assistant le lance en lecture seule avant l'étape 2)
        $statements = $this->statements('passation-releves-sql');
        $this->assertCount(6, $statements);
        $connection = $this->dataset->connection;
        $personal = $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Ingredient perso du proprietaire', 'sauce');
        $before = $this->dataset->hashesWithoutCreator();

        // R1 : un seul administrateur, actif
        $this->assertEquals([['ACTIVE', 1]], array_map(static fn(array $row): array => [$row[0], (int) $row[1]], $connection->fetchAllNumeric($statements[0])));
        // R2 : le compte vide a ses listes (dont une envoyée) et ses choix sur des anciens communs ; les comptes en attente et désactivé, rien
        $rows = $connection->fetchAllAssociative($statements[1]);
        $this->assertCount(3, $rows);
        $byStatus = array_column($rows, null, 'status');
        $this->assertSame([2, 1, 5, 1, 2], array_map('intval', array_values(array_diff_key($byStatus['ACTIVE'], ['status' => 1]))));
        $this->assertSame([0, 0, 0, 0, 0], array_map('intval', array_values(array_diff_key($byStatus['PENDING'], ['status' => 1]))));
        // R3 : l'origine des alias : la migration seule
        $this->assertEquals([['adoption', 'migration-essai', 3 * 261]], array_map(static fn(array $row): array => [$row[0], $row[1], (int) $row[2]], $connection->fetchAllNumeric($statements[2])));
        // R4 : les comptes par statut
        $this->assertEquals(['ACTIVE' => 5, 'DISABLED' => 1, 'PENDING' => 1], array_map('intval', array_column($connection->fetchAllNumeric($statements[3]), 1, 0)));
        // R5 : la photographie, qui dit ce que les comptages directs disent
        $snapshot = $connection->fetchAssociative($statements[4]);
        foreach (['comptes' => '"user"', 'conversions' => 'conversion', 'alias' => 'ingredient_alias', 'lignes_de_recettes' => 'recipe_row', 'lignes_de_listes' => 'shopping_list_row', 'ingredients_de_listes' => 'shopping_list_ingredient', 'choix_du_drive' => 'drive_choice', 'produits_des_choix' => 'drive_choice_product'] as $column => $table) {
            $this->assertSame((int) $connection->fetchOne('SELECT count(*) FROM ' . $table), (int) $snapshot[$column], $column);
        }
        $this->assertSame((int) $connection->fetchOne('SELECT count(*) FROM ingredient'), (int) $snapshot['ingredients']);
        $this->assertSame((int) $connection->fetchOne("SELECT count(*) FROM shopping_list WHERE status = 'SENT'"), (int) $snapshot['listes_envoyees']);
        $this->assertGreaterThanOrEqual(5, (int) $snapshot['listes_envoyees']);
        $this->assertSame(261, (int) $snapshot['sans_createur']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $snapshot['somme_des_listes']);
        // R6 : le propriétaire a un ingrédient à lui, créé depuis R1 (son slug finit par son identifiant)
        $this->assertSame([1, 1, 0], array_map('intval', $connection->fetchNumeric($statements[5])));
        $this->assertSame($this->dataset->ownerId, $connection->fetchOne('SELECT creator FROM ingredient WHERE slug = ?', [$personal]));

        // …et ces relevés n'écrivent rien
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
    }

    public function testTheBeforeChecksGiveWhatTheDocumentSaysBeforeAndAfterTheSeeding(): void
    {
        // Given : le document a cinq contrôles « avant »
        $statements = $this->statements('passation-controles-avant-sql');
        $this->assertCount(5, $statements);
        $connection = $this->dataset->connection;

        // Avant le classement et l'amorçage (étape 5) : le classement n'est pas posé, et le compte vide a des lignes sur les anciens communs
        $communs = $connection->fetchAssociative($statements[1]);
        $this->assertSame([261, 0, 0, 0, 'a43e401aaf59b6e65bd1f433f7347c7b', null], [(int) $communs['communs'], (int) $communs['de_base'], (int) $communs['avec_origine'], (int) $communs['au_format_d_un_compte'], $communs['somme_des_slugs'], $communs['somme_des_slugs_de_base']]);
        $this->assertGreaterThan(0, (int) $communs['conversions_propres']);
        $lines = array_column($connection->fetchAllNumeric($statements[2]), 1, 0);
        $this->assertEqualsCanonicalizing(['recettes', 'lignes de liste', 'ingredients de liste', 'choix du drive'], array_keys($lines));
        $this->assertSame(0, (int) $lines['recettes']);
        $this->assertGreaterThan(0, (int) $lines['lignes de liste'], 'le compte vide a des lignes sur les anciens communs');
        $this->assertGreaterThan(0, (int) $lines['choix du drive']);
        $health = $connection->fetchAssociative($statements[4]);
        $this->assertSame([1, 1], [(int) $health['actifs_sans_ingredient'], (int) $health['actifs_sans_amorcage']]);

        // When : classement et amorçage (étape 7)
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->tester->getDisplay());

        // Then (étape 8) : le propriétaire est actif, administrateur, sans ingrédient ni alias hérité des communs
        $this->assertSame(['ACTIVE', true, 0, 0], $this->owner($connection->fetchAssociative($statements[0])));
        // …les 261 anciens communs, dont 151 « de base » (les sommes de contrôle du document)
        $communs = $connection->fetchAssociative($statements[1]);
        $this->assertSame([261, 151, 0, 0, 'a43e401aaf59b6e65bd1f433f7347c7b', '3776a0ad5d1fbc427f9431656c14b7e2'], [(int) $communs['communs'], (int) $communs['de_base'], (int) $communs['avec_origine'], (int) $communs['au_format_d_un_compte'], $communs['somme_des_slugs'], $communs['somme_des_slugs_de_base']]);
        // …plus aucune ligne d'un autre compte sur un ancien commun
        $this->assertEquals(['recettes' => 0, 'lignes de liste' => 0, 'ingredients de liste' => 0, 'choix du drive' => 0], array_map('intval', array_column($connection->fetchAllNumeric($statements[2]), 1, 0)));
        // …chaque compte (sans identifiant ni adresse) : le propriétaire d'abord, puis par statut ; le compte vide a ses 151 « de base » et leurs cinq voisins
        $accounts = $connection->fetchAllAssociative($statements[3]);
        $this->assertCount(7, $accounts);
        $this->assertSame([true, 'ACTIVE', 0, 0, 0], [(bool) $accounts[0]['proprietaire'], $accounts[0]['status'], (int) $accounts[0]['ingredients'], (int) $accounts[0]['alias_adoption'], (int) $accounts[0]['de_base']]);
        $this->assertSame(array_fill(0, 6, false), array_map(static fn(array $row): bool => (bool) $row['proprietaire'], array_slice($accounts, 1)));
        $this->assertContains(156, array_map(static fn(array $row): int => (int) $row['ingredients'], $accounts), 'le compte vide : 151 « de base » et 5 demandés par ses lignes');
        $this->assertContains(156, array_map(static fn(array $row): int => (int) $row['alias_adoption'], $accounts));
        // …la santé : plus aucun compte actif sans ingrédient ni sans amorçage
        $this->assertSame([0, 0], array_map('intval', $connection->fetchNumeric($statements[4])));
    }

    public function testTheAfterChecksGiveWhatTheDocumentSaysAndMatchTheBeforeState(): void
    {
        // Given : le classement et l'amorçage, puis l'état d'avant la passation (contrôles 2 et 5 « avant » de l'étape 11)
        $before = $this->statements('passation-controles-avant-sql');
        $after = $this->statements('passation-controles-apres-sql');
        $this->assertCount(6, $after);
        $connection = $this->dataset->connection;
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->tester->getDisplay());
        $communsBefore = $connection->fetchAssociative($before[1]);
        $snapshotBefore = $connection->fetchAssociative($after[4]);
        $this->assertGreaterThanOrEqual(5, (int) $snapshotBefore['listes_envoyees']);

        // When
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->tester->getDisplay());

        // Then 1 : plus aucun ingrédient sans créateur
        $this->assertSame(0, (int) $connection->fetchOne($after[0]));
        // 2 : les anciens communs sont chez le propriétaire, identiques à avant (mêmes sommes de contrôle, mêmes conversions propres)
        $communs = $connection->fetchAssociative($after[1]);
        $this->assertSame([261, 151, 'a43e401aaf59b6e65bd1f433f7347c7b', '3776a0ad5d1fbc427f9431656c14b7e2', (int) $communsBefore['conversions_propres']], [(int) $communs['anciens_communs'], (int) $communs['de_base'], $communs['somme_des_slugs'], $communs['somme_des_slugs_de_base'], (int) $communs['conversions_propres']]);
        // 3 : l'invariant « toute ligne désigne un ingrédient du compte qui la possède », pour tous les comptes
        $this->assertEquals(['recettes' => 0, 'lignes de liste' => 0, 'ingredients de liste' => 0, 'choix du drive' => 0], array_map('intval', array_column($connection->fetchAllNumeric($after[2]), 1, 0)));
        // 4 : aucune référence orpheline
        $this->assertEquals(['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0, 'drive_choice' => 0, 'conversion' => 0], array_map('intval', array_column($connection->fetchAllNumeric($after[3]), 1, 0)));
        // 5 : rien n'a bougé ailleurs : listes envoyées, versions, nombres de lignes, mêmes chiffres, mêmes sommes qu'avant
        $this->assertSame($snapshotBefore, $connection->fetchAssociative($after[4]));
        // 6 : la santé
        $this->assertSame([0, 0], array_map('intval', $connection->fetchNumeric($after[5])));
    }

    public function testTheAfterChecksSeeAMistakeBeforeTheHandOverAndAfterAStrayLine(): void
    {
        // Given : avant la passation, les anciens communs n'ont pas de créateur
        $after = $this->statements('passation-controles-apres-sql');
        $connection = $this->dataset->connection;

        // Then : le contrôle 1 voit les 261, l'invariant du contrôle 3 voit les lignes du propriétaire sur des ingrédients sans créateur
        $this->assertSame(261, (int) $connection->fetchOne($after[0]));
        $invariant = array_map('intval', array_column($connection->fetchAllNumeric($after[2]), 1, 0));
        $this->assertGreaterThan(0, $invariant['recettes']);
        $this->assertGreaterThan(0, $invariant['choix du drive']);

        // When : la passation est faite, puis une ligne d'un compte est remise sur un ingrédient d'un autre (un vieil onglet) et une référence orpheline apparaît
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $this->assertSame(0, (int) $connection->fetchOne($after[0]));
        $migrated = $this->dataset->migratedIds[0];
        $this->dataset->adoption->recipe($migrated, 'Recette de retard', ['farine', 'ingredient-fantome']);

        // Then : l'invariant voit la ligne sur « farine » (qui est au propriétaire), le contrôle 4 voit l'orphelin
        $this->assertSame(1, (int) array_column($connection->fetchAllNumeric($after[2]), 1, 0)['recettes']);
        $this->assertSame(1, (int) array_column($connection->fetchAllNumeric($after[3]), 1, 0)['recipe_row']);
    }

    public function testTheDocumentedRollbackSqlPutsTheOldCommonsBackWithoutCreatorAndANewHandOverRestoresTheState(): void
    {
        // Given : le propriétaire a un ingrédient à lui, créé depuis (slug avec son identifiant), et la passation est faite
        $connection = $this->dataset->connection;
        $owner = $this->dataset->ownerId;
        $personal = $this->dataset->adoption->personalIngredient($owner, 'Ingredient perso du proprietaire', 'sauce');
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->tester->getDisplay());
        $beforeHandOver = $this->dataset->hashesWithoutCreator();
        $creatorsBefore = $this->creators();
        $statisticsBefore = $this->ingredientStatistic();
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->tester->getDisplay());
        $afterHandOver = $this->dataset->hashesWithoutCreator();
        $creatorsAfter = $this->creators();
        $this->assertSame($statisticsBefore + 261, $this->ingredientStatistic());
        $this->assertNotSame($creatorsBefore, $creatorsAfter);

        // When : le SQL du document, exécuté tel quel
        $rollback = $this->statements('passation-retour-arriere-sql');
        $this->assertCount(2, $rollback);
        $connection->executeStatement($rollback[0]);
        // (en exploitation la commande est un autre processus ; ici l'unité de travail a gardé le propriétaire en mémoire)
        $this->entityManager->clear();

        // Then : les 261 anciens communs, et eux seuls, sont de nouveau sans créateur, l'ingrédient du propriétaire reste à lui,
        // et le reste de la base est celui d'avant la passation, table par table
        $this->assertSame(261, (int) $connection->fetchOne($rollback[1]));
        $this->assertSame($owner, $connection->fetchOne('SELECT creator FROM ingredient WHERE slug = ?', [$personal]));
        $this->assertSame($creatorsBefore, $this->creators());
        $this->assertSame($beforeHandOver, $this->dataset->hashesWithoutCreator());
        // …y compris le compteur « ingrédients créés » du propriétaire, que la passation avait fait monter de 261
        $this->assertSame($statisticsBefore, $this->ingredientStatistic());

        // When : une nouvelle passation
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->tester->getDisplay());

        // Then : l'état d'après est rétabli à l'identique, le compteur compte de nouveau 261 (une fois)
        $this->assertSame($creatorsAfter, $this->creators());
        $this->assertSame($afterHandOver, $this->dataset->hashesWithoutCreator());
        $this->assertSame($statisticsBefore + 261, $this->ingredientStatistic());
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true]), $this->tester->getDisplay());
    }

    public function testTheRollbackNeverTouchesACopyOrAnIngredientCreatedByAnotherAccount(): void
    {
        // Given : la passation est faite
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $connection = $this->dataset->connection;
        $copiesBefore = (string) $connection->fetchOne("SELECT md5(string_agg(slug || '=' || creator, ',' ORDER BY slug COLLATE \"C\")) FROM ingredient WHERE adopted_from IS NOT NULL");

        // When
        $connection->executeStatement($this->statements('passation-retour-arriere-sql')[0]);

        // Then : les copies des autres comptes et leurs ingrédients personnels n'ont pas bougé
        $this->assertSame($copiesBefore, (string) $connection->fetchOne("SELECT md5(string_agg(slug || '=' || creator, ',' ORDER BY slug COLLATE \"C\")) FROM ingredient WHERE adopted_from IS NOT NULL"));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL AND (adopted_from IS NOT NULL OR slug ~ ?)', ['[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$']));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{string, bool, int, int}
     */
    private function owner(array $row): array
    {
        return [$row['status'], (bool) $row['administrateur'], (int) $row['ingredients'], (int) $row['alias']];
    }

    private function creators(): string
    {
        return (string) $this->dataset->connection->fetchOne("SELECT md5(string_agg(slug || '=' || coalesce(creator, '∅'), ',' ORDER BY slug COLLATE \"C\")) FROM ingredient");
    }

    private function ingredientStatistic(): int
    {
        return (int) (json_decode((string) $this->dataset->connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$this->dataset->ownerId]), true)['ingredients'] ?? 0);
    }

    /**
     * Le bloc SQL qui suit le marqueur `<!-- $marker -->` de `sans-commun.md`, tel qu'écrit, avec `:'owner'` remplacé par l'identifiant du propriétaire.
     */
    private function documentedSql(string $marker): string
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/ingredients/sans-commun.md');
        $this->assertSame(1, preg_match('/<!-- ' . preg_quote($marker, '/') . ' -->\\R```sql\\R(.*?)\\R```/s', $document, $matches), "le marqueur $marker est dans le document");

        return str_replace(":'owner'", $this->dataset->connection->quote($this->dataset->ownerId), $matches[1]);
    }

    /**
     * @return list<string> les requêtes du bloc, sans les commentaires
     */
    private function statements(string $marker): array
    {
        $sql = preg_replace('/^--.*$/m', '', $this->documentedSql($marker));

        return array_values(array_filter(array_map('trim', explode(';', (string) $sql)), static fn(string $statement): bool => $statement !== ''));
    }
}

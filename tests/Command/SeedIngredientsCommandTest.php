<?php

namespace Repas\Tests\Command;


use Repas\Command\SeedIngredientsCommand;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SeedingDataset;
use Repas\Tests\Helper\SeedingMeasures;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le rattrapage de l'amorçage des ingrédients (ING-008) de bout en bout : la commande de console `repas:ingredients:seed`, les vrais bus, la
 * vraie base, sur un jeu de données qui ressemble à la production d'avant la passation (`SeedingDataset`). Chaque propriété exigée par le
 * ticket est prouvée ici, avec des comptages et des sommes de contrôle calculés INDÉPENDAMMENT du code de production.
 */
class SeedIngredientsCommandTest extends DatabaseTestCase
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

    public function testTheDryRunWritesNothingAndReportsEverything(): void
    {
        // Given
        $before = $this->dataset->adoption->tableHashes();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : sans option (à blanc) puis --check
        $dryRun = $this->tester->execute([]);
        $dryRunOutput = $this->display();
        $check = $this->check();

        // Then : aucune ligne de aucune table n'a bougé, et aucune requête d'écriture ni verrou n'a même été envoyé
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
        $this->assertGreaterThan(20, $counter->count());
        $this->assertSame([], array_values(array_filter(
            $counter->statements(),
            static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|\\b(INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+\\w+\\s+SET)\\b|pg_advisory/i', $sql) === 1,
        )));
        $this->assertSame(Command::SUCCESS, $dryRun);
        $this->assertSame(Command::FAILURE, $check, '--check dit qu\'il reste à faire');
        $this->assertStringContainsString('à blanc (lecture seule)', $dryRunOutput);
        $this->assertStringContainsString("À blanc : rien n'a été modifié.", $dryRunOutput);
        $this->assertStringContainsString('Catalogue : 12 ingrédient(s) du propriétaire dont 8 « de base » (jamais modifiés), 3 conversion(s) propre(s) de ces ingrédients de base, slug le plus long : 15 caractères.', $dryRunOutput);
        $this->assertStringContainsString('Comptes : 9 dont 1 propriétaire du catalogue et 6 retenu(s) pour l\'amorçage.', $dryRunOutput);
        $this->assertStringContainsString('ignoré, propriétaire du catalogue', $dryRunOutput);
        $this->assertStringContainsString('ignoré, pas actif (il sera servi à son activation)', $dryRunOutput);
        $this->assertStringContainsString('à copier : 12 ingrédient(s) dont 8 « de base » et 4 demandé(s) par ses lignes (déjà présents et désignés sans copie : 0), dont 1 slug(s) à suffixer ; 3 conversion(s) propre(s) à copier', $dryRunOutput, 'le gros compte');
        $this->assertStringContainsString('à copier : 8 ingrédient(s) dont 8 « de base » et 0 demandé(s) par ses lignes', $dryRunOutput);
        $this->assertStringContainsString('dont 1 envoyée(s) au drive', $dryRunOutput);
        $this->assertStringContainsString('exigera --accept-sent', $dryRunOutput);
        $this->assertStringContainsString('Totaux : 53 copie(s) à créer (dont 1 à slug suffixé), 18 conversion(s) à copier', $dryRunOutput);
        $this->assertMatchesRegularExpression('/empreinte du contenu \(identique avant et après\) : [0-9a-f]{12}/', $dryRunOutput);
        // La requête de santé est rouge avant l'écriture : des comptes actifs sans amorçage, et des lignes sur les anciens communs
        $this->assertStringContainsString('comptes actifs sans amorçage (hors propriétaire) : 6', $dryRunOutput);
        $this->assertStringContainsString('verdict : ROUGE', $dryRunOutput);
        $this->assertStringContainsString('lignes du propriétaire sur un ingrédient qui n\'est pas à lui : aucune', $dryRunOutput);
    }

    public function testTheOutputNeverContainsAnEmailAnAccountIdentifierOrAPersonalIngredientName(): void
    {
        // When : le rapport, une écriture complète et la vérification n'affichent rien de personnel
        $outputs = [];
        $this->tester->execute([]);
        $outputs[] = $this->display();
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $outputs[] = $this->display();
        $this->check();
        $outputs[] = $this->display();

        // Then
        $output = implode("\n", $outputs);
        $connection = $this->dataset->adoption->connection;
        $secrets = [
            ...$connection->fetchFirstColumn('SELECT email FROM "user"'),
            ...$connection->fetchFirstColumn('SELECT id FROM "user"'),
            // Les noms des ingrédients PERSONNELS ; ceux qui sont aussi le nom d'un ingrédient du catalogue (« Tomate ») sont publics
            ...$connection->fetchFirstColumn('SELECT name FROM ingredient WHERE creator IS NOT NULL AND adopted_from IS NULL AND name NOT IN (SELECT name FROM ingredient WHERE creator IS NULL)'),
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
        $before = $this->dataset->adoption->tableHashes();

        // When
        $status = $this->tester->execute(['--apply' => true]);

        // Then : rien n'est écrit, la commande dit pourquoi et comment
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
        $this->assertStringContainsString('1 liste(s) déjà envoyée(s) au drive seraient repointées', $this->display());
        $this->assertStringContainsString('--accept-sent', $this->display());
    }

    public function testApplySeedsEveryActiveUnseededAccountAndTouchesNoOneElse(): void
    {
        // Given : l'état d'avant, mesuré à part
        $dataset = $this->dataset;
        $connection = $dataset->adoption->connection;
        $seeded = $dataset->accountsToCatchUp();
        $untouched = [$dataset->ownerId, $dataset->adoption->disabledId, $dataset->pendingId];
        $untouchedHashes = array_map($dataset->adoption->hashOfUser(...), $untouched);
        $catalogHash = $this->catalogHash();
        $contentBefore = array_map($this->contentOf(...), $seeded);
        $driveBefore = array_map($this->driveContentOf(...), $seeded);
        $versionsBefore = array_map($this->versionsOf(...), $seeded);
        $statisticsBefore = array_map(fn(string $userId): string => (string) $connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]), [...$seeded, ...$untouched]);
        $personalTomatoBefore = $connection->fetchAssociative('SELECT * FROM ingredient WHERE slug = ?', ['tomate' . $dataset->adoption->bigId]);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'rattrapage-essai']);

        // Then : succès, « rien à faire » et santé verte en fin de lancement
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertStringContainsString('Rien à faire : 0 à copier, 0 à repointer, empreintes identiques à celles d\'avant.', $this->display());
        // …mais la santé reste rouge pour un seul motif : le compte désactivé a deux lignes de recette sur les anciens communs (il n'est servi que sur demande)
        $this->assertStringContainsString('comptes actifs sans amorçage (hors propriétaire) : 0', $this->display());
        $this->assertStringContainsString('recettes : 2 lignes du propriétaire', $this->display());
        $this->assertStringContainsString('verdict : ROUGE', $this->display());

        // Les comptes hors périmètre (le propriétaire, un compte désactivé, un compte en attente) : rien, ni copie, ni alias, ni ligne, ni version
        foreach ($untouched as $index => $userId) {
            $this->assertSame($untouchedHashes[$index], $dataset->adoption->hashOfUser($userId));
        }
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id IN (?, ?, ?)', $untouched));
        // Le catalogue : intact (aucun supprimé, aucun modifié, le marqueur non plus, leurs conversions non plus)
        $this->assertSame($catalogHash, $this->catalogHash());
        $this->assertSame(12, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$dataset->ownerId]));
        $this->assertSame(8, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND is_base', [$dataset->ownerId]));

        // Ce que chaque compte a reçu : les huit « de base », plus ce que ses lignes référençaient
        $expected = ['big' => [...SeedingDataset::BASE, ...SeedingDataset::SPECIFIC], 'copier' => [...SeedingDataset::BASE, 'ingredient-008']];
        foreach ($seeded as $index => $userId) {
            $label = match ($userId) {
                $dataset->adoption->bigId => 'big',
                $dataset->adoption->copierId => 'copier',
                default => 'base',
            };
            $sources = $expected[$label] ?? SeedingDataset::BASE;
            sort($sources);
            $this->assertSame($sources, array_keys($this->copiesOf($userId)), $label);
            // Une copie par origine, conforme, à lui, qui naît « de base » faux, avec un alias d'adoption de la source du lancement
            $this->assertSame([], $connection->fetchFirstColumn(
                'SELECT c.slug FROM ingredient c JOIN ingredient_alias a ON a.user_id = :u AND a.alias_slug = c.slug LEFT JOIN ingredient o ON o.slug = a.ingredient_slug'
                . " WHERE a.kind = 'adoption' AND (o.slug IS NULL OR o.creator <> :u OR o.adopted_from <> c.slug OR o.name <> c.name OR o.image <> c.image OR o.is_base"
                . "   OR o.department <> c.department OR o.default_cooking_unit <> c.default_cooking_unit OR o.default_purchase_unit <> c.default_purchase_unit"
                . "   OR o.compatible_units::text <> c.compatible_units::text OR a.source <> 'rattrapage-essai')",
                ['u' => $userId],
            ), $label);
            // Plus aucune ligne sur l'ingrédient d'un autre compte (recettes, listes dont l'envoyée, choix du drive)
            $this->assertSame(['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0, 'drive_choice' => 0], $this->linesOnAnIngredientOfSomeoneElse($userId), $label);
            // Aucune perte : même contenu (nom, rayon, quantité, unité) par recette et par liste, mêmes choix du drive et produits
            $this->assertSame($contentBefore[$index], $this->contentOf($userId), $label);
            $this->assertSame($driveBefore[$index], $this->driveContentOf($userId), $label);
        }

        // Les conversions PROPRES sont copiées (beurre 2, farine 1 par compte), les générales jamais, celles du catalogue ne bougent pas
        $this->assertSame(6 * 3, (int) $connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.adopted_from IS NOT NULL'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
        $this->assertSame(
            [[125.0, 'unite', 'gramme'], [15.5, 'cuillere-a-soupe', 'gramme']],
            array_map(static fn(array $row): array => [(float) $row[0], $row[1], $row[2]], $connection->fetchAllNumeric(
                'SELECT coefficient, start_unit, end_unit FROM conversion WHERE ingredient = ? ORDER BY start_unit DESC',
                [StringTool::slugify('Beurre' . $dataset->adoption->bigId)],
            )),
        );
        $this->assertSame(6 * 3, (int) $connection->fetchOne('SELECT count(DISTINCT id) FROM conversion c WHERE c.ingredient IN (SELECT slug FROM ingredient WHERE adopted_from IS NOT NULL)'));

        // La collision : « Tomate » personnel du gros compte garde son slug et sa ligne, la copie prend « -2 » ; un nom qui finit par un symbole
        // prend le slug que l'ingrédient créé à la main aurait
        $this->assertSame($personalTomatoBefore, $connection->fetchAssociative('SELECT * FROM ingredient WHERE slug = ?', ['tomate' . $dataset->adoption->bigId]));
        $this->assertSame(StringTool::slugify('Tomate-2' . $dataset->adoption->bigId), $connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'tomate'", [$dataset->adoption->bigId]));
        $this->assertSame(StringTool::slugify('Couche (taille 4)' . $dataset->freshId), $connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'couche-taille-4'", [$dataset->freshId]));

        // Les versions : +1 pour chaque recette et liste modifiée, rien pour le compte neuf ; statut et date d'envoi de la liste envoyée inchangés
        foreach ([$dataset->adoption->bigId, $dataset->adoption->copierId, $dataset->adoption->singleId, $dataset->adoption->emptyId, $dataset->adoption->adminId] as $userId) {
            $position = array_search($userId, $seeded, true);
            $this->assertSame(array_map(static fn(int $version): int => $version + 1, $versionsBefore[$position]), $this->versionsOf($userId), 'toute recette et toute liste de ce compte référence un ingrédient copié');
        }
        $this->assertSame([], $this->versionsOf($dataset->freshId));
        $this->assertSame(['SENT', '2026-10-10 12:00:00'], $connection->fetchNumeric('SELECT status, sent_at FROM shopping_list WHERE id = ?', [$dataset->adoption->bigLists['SENT']]));
        // Le choix du drive sur l'ingrédient PERSONNEL du gros compte n'a pas bougé
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug = ?', [$dataset->adoption->bigId, StringTool::slugify('Praline' . $dataset->adoption->bigId)]));
        // Aucune statistique ne bouge : un amorçage n'est pas une création
        $this->assertSame($statisticsBefore, array_map(fn(string $userId): string => (string) $connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]), [...$seeded, ...$untouched]));
        $this->assertSame(6, json_decode($connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$dataset->adoption->bigId]), true)['ingredients']);

        // La santé est rouge (le compte désactivé), --check le dit ; on le sert explicitement, et elle devient verte
        $this->assertSame(SeedIngredientsCommand::EXIT_HEALTH_RED, $this->check());
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--user' => [$dataset->adoption->disabledId]]), $this->display());
        $this->assertCount(8, $this->copiesOf($dataset->adoption->disabledId));
        $this->assertSame(Command::SUCCESS, $this->check(), $this->display());
    }

    public function testTheOwnerNamedExplicitlyIsStillNeverSeeded(): void
    {
        // Given
        $before = $this->dataset->adoption->hashOfUser($this->dataset->ownerId);
        $reference = IngredientSeedingReportPostgreSQLLoader::referenceOf($this->dataset->ownerId);

        // When : même avec --user et --complete
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$reference], '--complete' => true]);

        // Then : ses ingrédients sont les siens, rien n'est écrit
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertSame($before, $this->dataset->adoption->hashOfUser($this->dataset->ownerId));
        $this->assertSame(0, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
        $this->assertStringContainsString('ignoré, propriétaire du catalogue', $this->display());
    }

    public function testASecondApplyWritesNothingAndCheckSaysThereIsNothingLeftToDo(): void
    {
        // Given : un premier lancement complet (le compte désactivé est servi sur demande)
        $this->catchUpEveryone();
        $afterFirst = $this->dataset->adoption->tableHashes();

        // When : le deuxième lancement n'a pas besoin de --accept-sent (rien ne serait modifié)
        $second = $this->tester->execute(['--apply' => true]);
        $secondOutput = $this->display();
        $check = $this->check();

        // Then : aucune ligne de aucune table n'a bougé (versions comprises), et --check le dit
        $this->assertSame(Command::SUCCESS, $second, $secondOutput);
        $this->assertSame($afterFirst, $this->dataset->adoption->tableHashes());
        $this->assertSame(Command::SUCCESS, $check, $this->display());
        $this->assertStringContainsString('Rien à faire : 0 à copier, 0 à repointer, la requête de santé est verte.', $this->display());
        $this->assertStringContainsString('Comptes : 9 dont 1 propriétaire du catalogue et 0 retenu(s)', $this->display());
    }

    public function testAlreadySeededAccountsAreNeverModifiedWhenAnotherAccountIsCaughtUp(): void
    {
        // Given : le gros compte est servi seul, puis le propriétaire marque un nouvel ingrédient « de base » (qui ne concerne que les comptes futurs)
        $dataset = $this->dataset;
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [IngredientSeedingReportPostgreSQLLoader::referenceOf($dataset->adoption->bigId)]]);
        $bigHash = $dataset->adoption->hashOfUser($dataset->adoption->bigId);
        $dataset->adoption->connection->executeStatement("UPDATE ingredient SET is_base = TRUE WHERE slug = 'ingredient-009'");

        // When : tous les autres sont rattrapés
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : le gros compte, déjà amorcé, n'a reçu ni complément ni modification (somme de contrôle de tout ce qui lui appartient)
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertSame($bigHash, $dataset->adoption->hashOfUser($dataset->adoption->bigId));
        $this->assertStringContainsString('ignoré, déjà amorcé', $this->display());
        // …alors que les autres ont reçu les neuf « de base »
        $this->assertCount(9, $this->copiesOf($dataset->freshId));
    }

    public function testACheckReportsTheCaseOfAnActiveAccountWithoutIngredientsAndALineOnSomeoneElsesIngredient(): void
    {
        // Given : tout est amorcé, puis un vieil onglet remet une ligne de recette du gros compte sur l'ancien commun
        $dataset = $this->dataset;
        $connection = $dataset->adoption->connection;
        $this->catchUpEveryone();
        $recipeId = $connection->fetchOne('SELECT id FROM recipe WHERE author = ? ORDER BY id LIMIT 1', [$dataset->adoption->bigId]);
        $connection->executeStatement("UPDATE recipe_row SET ingredient = 'farine' WHERE recipe = ? AND position = 0", [$recipeId]);

        // When
        $check = $this->check();

        // Then : la santé est rouge et la sortie dit où
        $this->assertSame(SeedIngredientsCommand::EXIT_HEALTH_RED, $check);
        $this->assertStringContainsString('lignes d\'un compte (hors propriétaire) sur un ingrédient qui n\'est pas à lui : recettes : 1', $this->display());
        $this->assertStringContainsString('verdict : ROUGE', $this->display());

        // When : --complete sur ce compte repointe la ligne sur sa copie (sans rien copier de plus)
        $copies = $connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL');
        $versionBefore = (int) $connection->fetchOne('SELECT version FROM recipe WHERE id = ?', [$recipeId]);
        $apply = $this->tester->execute(['--apply' => true, '--complete' => true, '--user' => [IngredientSeedingReportPostgreSQLLoader::referenceOf($dataset->adoption->bigId)]]);

        // Then
        $this->assertSame(Command::SUCCESS, $apply, $this->display());
        $this->assertSame($copies, $connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL'));
        $this->assertSame($versionBefore + 1, (int) $connection->fetchOne('SELECT version FROM recipe WHERE id = ?', [$recipeId]));
        $this->assertSame(0, $this->linesOnAnIngredientOfSomeoneElse($dataset->adoption->bigId)['recipe_row']);
        $this->assertSame(Command::SUCCESS, $this->check(), $this->display());

        // Given : un compte actif sans aucun ingrédient (un compte « amorcé » dont on a supprimé toutes les copies reste amorcé tant qu'un alias existe)
        $connection->executeStatement('DELETE FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL', [$dataset->freshId]);

        // When / Then : la santé le voit
        $this->assertSame(SeedIngredientsCommand::EXIT_HEALTH_RED, $this->check());
        $this->assertStringContainsString('comptes actifs sans aucun ingrédient : 1', $this->display());
    }

    public function testACheckSeesAnActiveAccountThatWasActivatedWhileTheSwitchWasOff(): void
    {
        // Given : un compte devenu actif sans rien recevoir (interrupteur coupé)
        $this->catchUpEveryone();
        $this->assertSame(Command::SUCCESS, $this->check());
        $this->dataset->adoption->connection->executeStatement("UPDATE \"user\" SET status = 'ACTIVE' WHERE id = ?", [$this->dataset->pendingId]);

        // When
        $check = $this->check();
        $apply = $this->tester->execute(['--apply' => true]);

        // Then : --check le dit, le rattrapage le sert, et la santé redevient verte
        $this->assertSame(Command::SUCCESS, $apply, $this->display());
        $this->assertSame(Command::FAILURE, $check);
        $this->assertCount(8, $this->copiesOf($this->dataset->pendingId));
        $this->assertSame(Command::SUCCESS, $this->check());
    }

    public function testUserAndExceptRestrictTheAccountsByShortReferenceOrFullIdentifierAndUserCanTargetAnyStatus(): void
    {
        // Given
        $dataset = $this->dataset;
        $connection = $dataset->adoption->connection;
        $reference = IngredientSeedingReportPostgreSQLLoader::referenceOf($dataset->adoption->singleId);

        // When : un seul compte, désigné par sa référence courte
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$reference]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        $this->assertCount(8, $this->copiesOf($dataset->adoption->singleId));
        foreach ([$dataset->adoption->bigId, $dataset->adoption->copierId, $dataset->adoption->emptyId, $dataset->freshId, $dataset->adoption->disabledId] as $other) {
            $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$other]));
        }
        $this->assertStringContainsString('hors de --user', $this->display());

        // When : tous sauf le gros compte et le compte neuf (identifiants complets) ; le compte désactivé n'est pas actif : ignoré
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--except' => [$dataset->adoption->bigId, $dataset->freshId]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        $this->assertCount(9, $this->copiesOf($dataset->adoption->copierId));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id IN (?, ?, ?)', [$dataset->adoption->bigId, $dataset->freshId, $dataset->adoption->disabledId]));
        $this->assertStringContainsString('écarté par --except', $this->display());

        // When : --user peut viser un compte désactivé (explicitement)
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$dataset->adoption->disabledId]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertCount(8, $this->copiesOf($dataset->adoption->disabledId));
    }

    public function testCompleteNeedsAnExplicitUserAndAddsOnlyWhatTheCatalogHasMore(): void
    {
        // Given : le compte neuf est amorcé, puis le propriétaire marque un nouvel ingrédient et on en ajoute une copie existante
        $dataset = $this->dataset;
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $dataset->adoption->connection->executeStatement("UPDATE ingredient SET is_base = TRUE WHERE slug = 'ingredient-009'");
        $freshBefore = $dataset->adoption->hashOfUser($dataset->freshId);
        $otherBefore = $dataset->adoption->hashOfUser($dataset->adoption->singleId);

        // When : --complete sans --user est refusé (jamais sur tous)
        $refused = $this->tester->execute(['--apply' => true, '--complete' => true]);
        $refusedOutput = $this->display();

        // Then
        $this->assertSame(Command::INVALID, $refused);
        $this->assertStringContainsString('--complete exige --user', $refusedOutput);
        $this->assertSame($freshBefore, $dataset->adoption->hashOfUser($dataset->freshId));

        // When : avec --user, le compte désigné reçoit le complément, un seul
        $status = $this->tester->execute(['--apply' => true, '--complete' => true, '--user' => [$dataset->freshId], '--source' => 'complement']);

        // Then : une seule copie de plus, d'une autre source ; les autres comptes ne sont pas touchés
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertCount(9, $this->copiesOf($dataset->freshId));
        $this->assertSame('complement', $dataset->adoption->connection->fetchOne("SELECT source FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'ingredient-009'", [$dataset->freshId]));
        $this->assertSame($otherBefore, $dataset->adoption->hashOfUser($dataset->adoption->singleId));
    }

    public function testWhenNothingIsMarkedBaseTheReportSaysSoAndApplyWritesNothing(): void
    {
        // Given : le classement n'est pas posé
        $connection = $this->dataset->adoption->connection;
        $connection->executeStatement('UPDATE ingredient SET is_base = FALSE');
        $before = $this->dataset->adoption->tableHashes();

        // When
        $dryRun = $this->tester->execute([]);
        $dryRunOutput = $this->display();
        $apply = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : une anomalie bloquante, rien n'est écrit, aucun compte n'est marqué amorcé
        $this->assertSame(Command::FAILURE, $dryRun);
        $this->assertStringContainsString('ANOMALIE BLOQUANTE : aucun ingrédient « de base » dans le catalogue', $dryRunOutput);
        $this->assertSame(Command::FAILURE, $apply);
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
    }

    public function testWithoutACatalogOwnerTheReportSaysSoAndApplyWritesNothing(): void
    {
        // Given : le propriétaire n'existe pas
        $connection = $this->dataset->adoption->connection;
        $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->dataset->ownerId]);
        $before = $this->dataset->adoption->tableHashes();

        // When
        $apply = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then
        $this->assertSame(Command::FAILURE, $apply);
        $this->assertStringContainsString("le propriétaire du catalogue n'existe pas", $this->display());
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
    }

    public function testTheCatchUpGivesEveryoneTheSameCopiesAndTheHealthIsGreen(): void
    {
        // When
        $this->catchUpEveryone();

        // Then : exactement les mêmes copies, la santé est verte, le propriétaire inclus (ses lignes sont sur ses ingrédients)
        $expected = [...SeedingDataset::BASE, ...SeedingDataset::SPECIFIC];
        sort($expected);
        $this->assertSame($expected, array_keys($this->copiesOf($this->dataset->adoption->bigId)));
        $this->assertCount(8, $this->copiesOf($this->dataset->freshId));
        $this->assertStringContainsString('verdict : VERT', $this->display());
        $this->assertSame(Command::SUCCESS, $this->check(), $this->display());
    }

    public function testTheOwnerIsIncludedInTheHealthChecks(): void
    {
        // Given : le propriétaire a une ligne de recette sur un ingrédient qui n'est pas à lui (un ingrédient d'un autre compte)
        $dataset = $this->dataset;
        $connection = $dataset->adoption->connection;
        $recipeId = $connection->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$dataset->ownerId]);
        $connection->executeStatement("UPDATE recipe_row SET ingredient = ? WHERE recipe = ? AND position = 0", ['tomate' . $dataset->adoption->bigId, $recipeId]);
        $this->catchUpEveryone();

        // When
        $check = $this->check();

        // Then : la santé du propriétaire est évaluée, et rouge
        $this->assertSame(SeedIngredientsCommand::EXIT_HEALTH_RED, $check);
        $this->assertStringContainsString('lignes du propriétaire sur un ingrédient qui n\'est pas à lui : recettes : 1', $this->display());
    }

    public function testAnOptionConflictAndAnInvalidRunNameAreRefused(): void
    {
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--check' => true]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => str_repeat('a', 65)]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => 'un lancement']));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--user' => ['ffffffff']]), 'un compte inconnu');
        $this->assertStringContainsString('Aucun compte ne correspond à : ffffffff', $this->display());
        $this->assertSame(0, (int) $this->dataset->adoption->connection->fetchOne('SELECT count(*) FROM ingredient_alias'));
    }

    public function testTheDefaultRunNameIsDatedAndWrittenInEveryAlias(): void
    {
        // When
        $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [IngredientSeedingReportPostgreSQLLoader::referenceOf($this->dataset->freshId)]]);

        // Then
        $sources = $this->dataset->adoption->connection->fetchFirstColumn('SELECT DISTINCT source FROM ingredient_alias WHERE user_id = ?', [$this->dataset->freshId]);
        $this->assertCount(1, $sources);
        $this->assertMatchesRegularExpression('/^rattrapage-\d{4}-\d{2}-\d{2}$/', $sources[0]);
    }

    public function testWritingIsRefusedOutsideTheAllowedEnvironmentsButReadingStaysPermitted(): void
    {
        // Given : la même commande, dans un environnement qui n'est pas prod ni dev
        $container = static::getContainer();
        $command = new SeedIngredientsCommand(
            $container->get(QueryBusInterface::class),
            $container->get(CommandBusInterface::class),
            $container->get(\Psr\Clock\ClockInterface::class),
            'staging',
            ['prod', 'dev'],
        );
        $tester = new CommandTester($command);
        $before = $this->dataset->adoption->tableHashes();

        // When
        $apply = $tester->execute(['--apply' => true, '--accept-sent' => true]);
        $applyOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());
        $dryRun = $tester->execute([]);

        // Then
        $this->assertSame(Command::INVALID, $apply);
        $this->assertStringContainsString('Écriture refusée dans l\'environnement « staging »', $applyOutput);
        $this->assertSame(Command::SUCCESS, $dryRun);
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
    }

    /**
     * Le rattrapage de tout le monde, comme l'exploitation le fera : le périmètre par défaut, puis le compte désactivé qui a des lignes sur
     * le catalogue, servi sur demande.
     */
    private function catchUpEveryone(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true]), $this->display());
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$this->dataset->adoption->disabledId]]), $this->display());
    }

    public function testTheCommandWorksWithTheAutomaticSwitchOffBecauseItDoesNotReadIt(): void
    {
        // Given : l'interrupteur de l'amorçage automatique est coupé (cas de la production avant l'allumage)
        $feature = static::getContainer()->get(\Repas\Repas\Domain\Interface\IngredientSeedingFeature::class);
        $this->assertFalse($feature->isEnabled());

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$this->dataset->freshId]]);

        // Then
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertCount(8, $this->copiesOf($this->dataset->freshId));
    }

    /**
     * La sortie de la commande, espaces et retours à la ligne ramenés à une espace : un bloc d'erreur est coupé à la largeur du terminal.
     */
    private function display(): string
    {
        return preg_replace('/\\s+/', ' ', $this->tester->getDisplay());
    }

    /**
     * Le verdict de `--check`, sans rien écrire.
     *
     * @phpstan-impure
     */
    private function check(): int
    {
        return $this->tester->execute(['--check' => true]);
    }
}

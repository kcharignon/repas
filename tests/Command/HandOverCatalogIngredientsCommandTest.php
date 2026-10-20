<?php

namespace Repas\Tests\Command;


use Repas\Command\HandOverCatalogIngredientsCommand;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\Tests\Helper\SeedingMeasures;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La passation du catalogue (ING-009) de bout en bout : la commande de console `repas:ingredients:hand-over-catalog`, les vrais bus, la vraie
 * base, sur un jeu de données qui ressemble à la production d'avant la passation (`HandoverDataset` : les 261 vrais anciens communs du fichier
 * validé, le propriétaire avec ses recettes, ses listes dont deux envoyées et ses choix du drive, six comptes migrés par le vrai cas d'usage
 * d'ING-003, le compte vide, un compte en attente, un compte désactivé). Chaque propriété exigée par le ticket est prouvée ici avec des
 * comptages et des sommes de contrôle calculés INDÉPENDAMMENT du code de production.
 */
class HandOverCatalogIngredientsCommandTest extends DatabaseTestCase
{
    use SeedingMeasures;

    private HandoverDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
    }

    public function testTheDryRunWritesNothingAndReportsEverything(): void
    {
        // Given
        $before = $this->dataset->hashesWithoutCreator();
        $creatorsBefore = $this->creatorsHash();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $conversions = (int) $this->connection()->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.creator IS NULL');

        // When : sans option (à blanc), puis --check
        $dryRun = $this->tester->execute([]);
        $dryRunOutput = $this->display();
        $queries = $counter->statements();
        $check = $this->tester->execute(['--check' => true]);
        // …puis à blanc avec les options du classement et de l'amorçage seulement : le code de sortie dit si --apply avec les MÊMES options écrirait
        $withoutConsent = $this->tester->execute(['--stop-before-handover' => true]);
        $withoutConsentOutput = $this->display();
        $withConsent = $this->tester->execute(['--stop-before-handover' => true, '--accept-sent' => true]);
        $withConsentOutput = $this->display();

        // Then : aucune ligne de aucune table n'a bougé, et aucune requête d'écriture ni verrou n'a même été envoyé
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame($creatorsBefore, $this->creatorsHash());
        $this->assertGreaterThan(20, count($queries));
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|\\b(INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+\\w+\\s+SET)\\b|pg_advisory/i', $sql) === 1,
        )));
        $this->assertSame(Command::FAILURE, $dryRun, '--apply (complet) refuserait : l\'interrupteur est coupé');
        $this->assertSame(Command::FAILURE, $check, '--check dit qu\'il reste à faire');
        $this->assertSame(Command::FAILURE, $withoutConsent, '--apply --stop-before-handover refuserait sans --accept-sent');
        $this->assertStringContainsString('1 liste(s) déjà envoyée(s) au drive seraient repointées', $withoutConsentOutput);
        $this->assertStringContainsString('relance avec --accept-sent', $withoutConsentOutput);
        $this->assertSame(Command::SUCCESS, $withConsent, '--apply --stop-before-handover --accept-sent écrirait');
        $this->assertStringContainsString('--apply, avec ces options, écrirait.', $withConsentOutput);
        $this->assertStringContainsString('à blanc du classement et de l\'amorçage seulement (lecture seule)', $withConsentOutput);
        $this->assertStringContainsString('à blanc (lecture seule)', $dryRunOutput);
        $this->assertStringContainsString("À blanc : rien n'a été modifié.", $dryRunOutput);
        $this->assertStringContainsString('--apply, avec ces options, refuserait d\'écrire.', $dryRunOutput);
        // Le propriétaire, le fichier, les anciens communs : les nombres et les sommes de contrôle du document
        $this->assertMatchesRegularExpression('/Propriétaire du catalogue : \\[[0-9a-f]{8}\\] actif, administrateur ; 1 compte\\(s\\) administrateur/', $dryRunOutput);
        $this->assertStringContainsString('Fichier de classement : 261 ingrédient(s) dont 151 « de base » et 110 « spécifiques » ; somme de contrôle des slugs a43e401aaf59b6e65bd1f433f7347c7b, de ceux « de base » 3776a0ad5d1fbc427f9431656c14b7e2.', $dryRunOutput);
        $this->assertStringContainsString(sprintf('Anciens communs : 261 sans créateur, 0 déjà au propriétaire (sans origine) parmi ceux du fichier ; %d conversion(s) propre(s)', $conversions), $dryRunOutput);
        $this->assertStringContainsString('261 ingrédient(s) sans créateur, somme de contrôle des slugs a43e401aaf59b6e65bd1f433f7347c7b (celle du fichier) ; dont 0 « de base »', $dryRunOutput);
        $this->assertStringContainsString('classement pas encore posé', $dryRunOutput);
        $this->assertStringContainsString('accord avec le fichier : exact ; phase 1 : 151 marqueur(s) à écrire ; 0 avec une origine, 0 au format d\'un slug de compte', $dryRunOutput);
        // Les comptes que l'amorçage servirait, comme si le classement était posé : le compte vide, ses « spécifiques » référencés, sa liste envoyée
        $this->assertStringContainsString('Amorçage (phase 2), classement lu comme posé : 10 compte(s) au total dont 1 retenu(s)', $dryRunOutput);
        $this->assertStringContainsString('le catalogue compterait 261 ingrédient(s) dont 151 « de base »', $dryRunOutput);
        $this->assertStringContainsString('RETENU — 0 ingrédient(s), 0 recette(s), non amorcé', $dryRunOutput);
        $this->assertStringContainsString('à copier : 156 ingrédient(s) dont 151 « de base » et 5 demandé(s) par ses lignes', $dryRunOutput);
        $this->assertStringContainsString('dont 1 envoyée(s) au drive', $dryRunOutput);
        // Les préconditions : P2 et P3 sont levées par l'amorçage, P4 (l'interrupteur est coupé en test) ne l'est pas
        $this->assertMatchesRegularExpression('/P1 : OK/', $dryRunOutput);
        $this->assertMatchesRegularExpression('/P2 : MANQUE — .*\\(levée par l\'amorçage\\)/', $dryRunOutput);
        $this->assertMatchesRegularExpression('/P3 : MANQUE — 1 compte\\(s\\) actif\\(s\\) sans amorçage, 1 compte\\(s\\) actif\\(s\\) sans aucun ingrédient \\(levée par l\'amorçage\\)/', $dryRunOutput);
        $this->assertStringContainsString('P4 : MANQUE — il est coupé', $dryRunOutput);
        foreach (['P5', 'P6', 'P7'] as $code) {
            $this->assertStringContainsString($code . ' : OK', $dryRunOutput);
        }
        $this->assertStringContainsString('Prêt pour le classement et l\'amorçage (--apply --stop-before-handover) : oui.', $dryRunOutput);
        $this->assertStringContainsString('Prêt pour la passation complète (--apply) : NON. Après le classement et l\'amorçage il manquera encore : P4.', $dryRunOutput);
    }

    public function testTheOutputNeverContainsAnEmailAnAccountIdentifierOrAPersonalIngredientName(): void
    {
        // Given
        $this->switchSeedingOn();

        // When : le rapport, le classement et l'amorçage, la passation complète et les vérifications n'affichent rien de personnel
        $outputs = [];
        $this->tester->execute([]);
        $outputs[] = $this->display();
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $outputs[] = $this->display();
        $this->tester->execute(['--apply' => true]);
        $outputs[] = $this->display();
        $this->tester->execute(['--check' => true]);
        $outputs[] = $this->display();

        // Then
        $output = implode("\n", $outputs);
        $connection = $this->connection();
        $secrets = [
            ...$connection->fetchFirstColumn('SELECT email FROM "user"'),
            ...$connection->fetchFirstColumn('SELECT id FROM "user"'),
            // Les noms des ingrédients PERSONNELS (ceux qui ne sont pas le nom d'un ancien commun)
            ...$connection->fetchFirstColumn("SELECT DISTINCT name FROM ingredient WHERE creator IS NOT NULL AND adopted_from IS NULL AND name LIKE 'Ingredient perso%'"),
            'example.com',
        ];
        $this->assertGreaterThan(14, count($secrets));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString('Passation faite et conforme', $this->display() . $outputs[2]);
    }

    public function testApplyIsRefusedBeforeAnyWriteWhileTheSeedingSwitchIsOffButStopBeforeHandoverWorks(): void
    {
        // Given : l'interrupteur de l'amorçage à l'activation est coupé (la production avant l'allumage)
        $before = $this->dataset->hashesWithoutCreator();

        // When : la passation complète
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : refusée AVANT toute écriture (ni classement, ni amorçage), la sortie dit P4
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('La passation complète serait refusée même une fois le classement posé et l\'amorçage fait : P4 : l\'amorçage à l\'activation est allumé (INGREDIENT_SEEDING_ENABLED) — il est coupé.', $this->display());
        $this->assertStringContainsString('--apply --stop-before-handover. Rien n\'a été modifié.', $this->display());
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame(261, $this->withoutCreator());

        // When : le classement et l'amorçage seulement
        $stop = $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);

        // Then : ils marchent, interrupteur coupé
        $this->assertSame(Command::SUCCESS, $stop, $this->display());
        $this->assertSame(151, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL AND is_base'));
    }

    public function testApplyIsRefusedBeforeAnyWriteWithoutAcceptSentWhileASentListIsConcerned(): void
    {
        // Given
        $this->switchSeedingOn();
        $before = $this->dataset->hashesWithoutCreator();

        // When
        $status = $this->tester->execute(['--apply' => true]);

        // Then : rien n'est écrit, la commande dit pourquoi et comment
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertStringContainsString('1 liste(s) déjà envoyée(s) au drive seraient repointées', $this->display());
        $this->assertStringContainsString('--accept-sent', $this->display());
    }

    public function testPhasesOneAndTwoClassifyAndSeedTheEmptyAccountAndTouchNoOneElse(): void
    {
        // Given : l'état d'avant, mesuré à part
        $dataset = $this->dataset;
        $connection = $this->connection();
        $migrated = $dataset->migratedIds;
        $untouched = [$dataset->ownerId, ...$migrated, $dataset->pendingId, $dataset->disabledId];
        $untouchedBefore = array_map($dataset->adoption->hashOfUser(...), $untouched);
        $contentBefore = $this->contentOf($dataset->emptyId);
        $driveBefore = $this->driveContentOf($dataset->emptyId);
        $versionsBefore = $this->versionsOf($dataset->emptyId);
        $emptyIngredientsBefore = (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL');
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE is_base'), 'le classement n\'est pas posé');

        // When
        $status = $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true, '--source' => 'rattrapage-essai']);
        $output = $this->display();

        // Then : succès, la passation n'est pas faite
        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertStringContainsString('Arrêt avant la passation (--stop-before-handover)', $output);
        $this->assertStringContainsString('La passation n\'est pas faite (--stop-before-handover).', $output);
        // Phase 1 : 151 de base, 110 spécifiques, exactement celles du fichier ; aucun ancien commun n'a changé de créateur
        $this->assertStringContainsString('261 ingrédient(s) sans créateur classés : 151 « de base », 110 « spécifiques » ; 151 marqueur(s) écrit(s).', $output);
        $this->assertSame($this->fileBase(), $connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator IS NULL AND is_base ORDER BY slug COLLATE "C"'));
        $this->assertSame(110, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL AND NOT is_base'));
        $this->assertSame($emptyIngredientsBefore, $this->withoutCreator());
        $this->assertSame('3776a0ad5d1fbc427f9431656c14b7e2', $connection->fetchOne("SELECT md5(string_agg(slug, ',' ORDER BY slug COLLATE \"C\")) FROM ingredient WHERE creator IS NULL AND is_base"));
        // Phase 2 : le compte vide reçoit les 151 « de base » ET ceux que ses lignes référencent, ses lignes sont repointées, rien n'est perdu
        $copies = $this->copiesOf($dataset->emptyId);
        $this->assertCount(156, $copies);
        $this->assertSame(array_values(array_diff(array_keys($copies), $this->fileBase())), array_values(array_intersect(array_keys($copies), array_diff($dataset->slugs, $this->fileBase()))), 'les cinq autres sont des « spécifiques » référencés');
        $this->assertCount(5, array_diff(array_keys($copies), $this->fileBase()));
        $this->assertSame(['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0, 'drive_choice' => 0], $this->linesOnAnIngredientOfSomeoneElse($dataset->emptyId));
        $this->assertSame($contentBefore, $this->contentOf($dataset->emptyId));
        $this->assertSame($driveBefore, $this->driveContentOf($dataset->emptyId));
        $this->assertSame(array_map(static fn(int $version): int => $version + 1, $versionsBefore), $this->versionsOf($dataset->emptyId));
        $this->assertSame(['SENT', '2026-10-10 12:00:00'], $connection->fetchNumeric("SELECT status, sent_at FROM shopping_list WHERE owner = ? AND status = 'SENT'", [$dataset->emptyId]));
        $this->assertSame(156, (int) $connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption' AND source = 'rattrapage-essai'", [$dataset->emptyId]));
        // Le propriétaire, les comptes migrés, le compte en attente et le compte désactivé : rien (copies, alias, lignes, versions, statistiques)
        foreach ($untouched as $index => $userId) {
            $this->assertSame($untouchedBefore[$index], $dataset->adoption->hashOfUser($userId), 'compte ' . $index);
        }
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id IN (?, ?, ?)', [$dataset->ownerId, $dataset->pendingId, $dataset->disabledId]));
        // La santé : la requête de santé du rapport est verte pour les comptes actifs
        $this->assertStringContainsString('comptes actifs sans amorçage (hors propriétaire) : 0', $output);
        $this->assertStringContainsString('comptes actifs sans aucun ingrédient : 0', $output);
        $this->assertStringContainsString('verdict : VERT', $output);
        // Rejouer ne fait rien et le dit
        $replayHashes = $dataset->hashesWithoutCreator();
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true]), $this->display());
        $this->assertSame($replayHashes, $dataset->hashesWithoutCreator());
        $this->assertStringContainsString('Classement déjà posé', $this->display());
        $this->assertStringContainsString('Aucun compte à amorcer.', $this->display());
    }

    public function testTheFullApplyHandsTheOldCommonsOverToTheOwnerAndNothingElseMoves(): void
    {
        // Given : l'interrupteur est allumé, le classement et l'amorçage sont faits, et l'état d'avant la passation est mesuré à part
        $dataset = $this->dataset;
        $connection = $this->connection();
        $this->switchSeedingOn();
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->display());
        $owner = $dataset->ownerId;
        $others = [...$dataset->migratedIds, $dataset->emptyId, $dataset->pendingId, $dataset->disabledId];
        $tablesBefore = $dataset->hashesWithoutCreator();
        $ownerBefore = $dataset->adoption->hashOfUser($owner, withOwnedIngredients: false, withStatistics: false);
        $othersBefore = array_map($dataset->adoption->hashOfUser(...), $others);
        $ownerVersions = $this->versionsOf($owner);
        $ownerContent = $this->contentOf($owner);
        $ownerDrive = $this->driveContentOf($owner);
        $ownerLists = $connection->fetchAllAssociative('SELECT id, status, sent_at, version FROM shopping_list WHERE owner = ? ORDER BY id', [$owner]);
        $ownerConversions = $connection->fetchAllAssociative('SELECT * FROM conversion WHERE ingredient IN (SELECT slug FROM ingredient WHERE creator IS NULL) ORDER BY id');
        $oldCommonsBefore = $connection->fetchAllAssociative('SELECT slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, compatible_units::text, adopted_from, is_base FROM ingredient WHERE creator IS NULL ORDER BY slug COLLATE "C"');
        $ingredientCountBefore = (int) $connection->fetchOne('SELECT count(*) FROM ingredient');
        $statisticsBefore = json_decode((string) $connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$owner]), true);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $status = $this->tester->execute(['--apply' => true]);
        $output = $this->display();
        $statements = $counter->statements();

        // Then : succès, passation faite et conforme
        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertStringContainsString('Passation faite et conforme : plus aucun ingrédient sans créateur, plus rien à amorcer, la requête de santé est verte.', $output);
        $this->assertStringContainsString('261 ingrédient(s) passé(s) au propriétaire en une instruction', $output);
        // Aucun ingrédient sans créateur ; les 261 sont au propriétaire, identiques (sommes de contrôle du document), 151 de base, sans origine
        $this->assertSame(0, $this->withoutCreator());
        $this->assertSame(
            [261, 151, 'a43e401aaf59b6e65bd1f433f7347c7b', '3776a0ad5d1fbc427f9431656c14b7e2'],
            [
                ...array_map('intval', [$connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NULL AND slug IN (?)', [$owner, $dataset->slugs], [1 => \Doctrine\DBAL\ArrayParameterType::STRING]), $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NULL AND is_base', [$owner])]),
                $connection->fetchOne("SELECT md5(string_agg(slug, ',' ORDER BY slug COLLATE \"C\")) FROM ingredient WHERE creator = ? AND adopted_from IS NULL", [$owner]),
                $connection->fetchOne("SELECT md5(string_agg(slug, ',' ORDER BY slug COLLATE \"C\")) FROM ingredient WHERE creator = ? AND adopted_from IS NULL AND is_base", [$owner]),
            ],
        );
        // …et rien d'autre n'a changé sur les 261 : seul `creator` (mêmes noms, rayons, unités, marqueurs, origine) ; leurs conversions propres non plus
        $this->assertSame($oldCommonsBefore, $connection->fetchAllAssociative('SELECT slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, compatible_units::text, adopted_from, is_base FROM ingredient WHERE creator = ? ORDER BY slug COLLATE "C"', [$owner]));
        $this->assertSame($ownerConversions, $connection->fetchAllAssociative('SELECT * FROM conversion WHERE ingredient IN (SELECT slug FROM ingredient WHERE creator = ?) ORDER BY id', [$owner]));
        // Aucun ingrédient créé ni supprimé, aucun alias de plus (le propriétaire n'en a jamais), aucune ligne de recette, de liste, de choix modifiée
        $this->assertSame($ingredientCountBefore, (int) $connection->fetchOne('SELECT count(*) FROM ingredient'));
        $tablesAfter = $dataset->hashesWithoutCreator();
        $this->assertSame($tablesBefore, $tablesAfter, 'toutes les tables sont identiques, sauf `ingredient.creator` (et la statistique du propriétaire)');
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$owner]));
        // Le propriétaire : mêmes versions, mêmes listes (dont les deux ENVOYÉES : statut et date), mêmes contenus, mêmes choix du drive et produits
        $this->assertSame($ownerBefore, $dataset->adoption->hashOfUser($owner, withOwnedIngredients: false, withStatistics: false));
        $this->assertSame($ownerVersions, $this->versionsOf($owner));
        $this->assertSame($ownerContent, $this->contentOf($owner));
        $this->assertSame($ownerDrive, $this->driveContentOf($owner));
        $this->assertSame($ownerLists, $connection->fetchAllAssociative('SELECT id, status, sent_at, version FROM shopping_list WHERE owner = ? ORDER BY id', [$owner]));
        $this->assertSame(2, (int) $connection->fetchOne("SELECT count(*) FROM shopping_list WHERE owner = ? AND status = 'SENT' AND sent_at = '2026-10-10 12:00:00'", [$owner]));
        // Les six comptes migrés, le compte vide amorcé, le compte en attente et le compte désactivé : strictement rien (copies, alias, lignes, versions)
        foreach ($others as $index => $userId) {
            $this->assertSame($othersBefore[$index], $dataset->adoption->hashOfUser($userId), 'compte ' . $index);
        }
        // Le compteur « ingrédients créés » du propriétaire a augmenté de 261, une fois
        $statisticsAfter = json_decode((string) $connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$owner]), true);
        $this->assertSame(($statisticsBefore['ingredients'] ?? 0) + 261, $statisticsAfter['ingredients']);
        // (le compteur de recettes de la mise à jour des statistiques vaut zéro quand il n'existait pas : la clé apparaît, la valeur ne bouge pas)
        $statisticsBefore['recipes'] ??= 0;
        unset($statisticsBefore['ingredients'], $statisticsAfter['ingredients']);
        $this->assertSame($statisticsBefore, $statisticsAfter, 'rien d\'autre ne bouge dans les statistiques');
        // La passation n'émet AUCUN `INSERT` ni `DELETE`, et ne met à jour que `ingredient` (le créateur) et `user` (la statistique)
        $this->assertSame([], array_values(array_filter($statements, static fn(string $sql): bool => preg_match('/^\\s*(INSERT|DELETE)\\b/i', $sql) === 1)));
        $updates = array_values(array_filter($statements, static fn(string $sql): bool => preg_match('/^\\s*UPDATE\\b/i', $sql) === 1));
        $this->assertCount(2, $updates, implode("\n", $updates));
        $this->assertMatchesRegularExpression('/^UPDATE ingredient SET creator = /', $updates[0]);
        $this->assertMatchesRegularExpression('/^UPDATE "?user"?/', $updates[1]);
        // Les contrôles SQL du document disent la même chose (voir `CatalogHandoverDocumentedSqlTest`)
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true]), $this->display());
    }

    public function testASecondApplyWritesNothingAndSaysSoAndCheckSaysThereIsNothingLeftToDo(): void
    {
        // Given : la passation est faite
        $this->switchSeedingOn();
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true]), $this->display());
        $afterFirst = $this->dataset->hashesWithoutCreator();
        $creators = $this->creatorsHash();
        $allTables = $this->dataset->adoption->tableHashes();
        $statistics = $this->connection()->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$this->dataset->ownerId]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : le deuxième lancement n'a pas besoin de --accept-sent (rien ne serait modifié)
        $second = $this->tester->execute(['--apply' => true]);
        $secondOutput = $this->display();
        $statements = $counter->statements();
        $check = $this->tester->execute(['--check' => true]);

        // Then : aucune ligne de aucune table n'a bougé (versions et statistiques comprises), aucune requête d'écriture, et la commande le dit
        $this->assertSame(Command::SUCCESS, $second, $secondOutput);
        $this->assertSame($afterFirst, $this->dataset->hashesWithoutCreator());
        $this->assertSame($creators, $this->creatorsHash());
        $this->assertSame($allTables, $this->dataset->adoption->tableHashes());
        $this->assertSame($statistics, $this->connection()->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$this->dataset->ownerId]), 'la statistique n\'est pas annoncée une seconde fois');
        $this->assertSame([], array_values(array_filter($statements, static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b/i', $sql) === 1)));
        $this->assertStringContainsString('Passation déjà faite : plus aucun ingrédient sans créateur, rien à passer. Rien n\'est écrit.', $secondOutput);
        $this->assertStringContainsString('Passation déjà faite : plus aucun ingrédient sans créateur, rien à classer.', $secondOutput);
        $this->assertSame(Command::SUCCESS, $check, $this->display());
        $this->assertStringContainsString('Rien à faire : passation faite et conforme. Rien n\'a été modifié.', $this->display());
        $this->assertStringContainsString('Anciens communs : 0 sans créateur, 261 déjà au propriétaire (sans origine) parmi ceux du fichier', $this->display());
        // Le dry-run d'après dit la même chose
        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('Rien à faire : passation faite et conforme.', $this->display());
    }

    public function testAClassificationTheOwnerChangedAfterTheHandOverIsRespectedAndOnlyReportedAsInformation(): void
    {
        // Given : la passation est faite, puis le propriétaire retire le marqueur d'un ingrédient (c'est son droit)
        $this->switchSeedingOn();
        $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $base = $this->fileBase()[0];
        $this->connection()->executeStatement('UPDATE ingredient SET is_base = FALSE WHERE slug = ?', [$base]);
        $before = $this->dataset->adoption->tableHashes();

        // When : un nouveau lancement et la vérification
        $apply = $this->tester->execute(['--apply' => true]);
        $check = $this->tester->execute(['--check' => true]);

        // Then : il n'écrit rien (le choix du propriétaire est respecté), la vérification reste verte et l'écart est une simple information
        $this->assertSame(Command::SUCCESS, $apply);
        $this->assertSame(Command::SUCCESS, $check, $this->display());
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
        $this->assertFalse((bool) $this->connection()->fetchOne('SELECT is_base FROM ingredient WHERE slug = ?', [$base]));
        $this->assertStringContainsString('1 marqueur(s) « de base » du propriétaire diffèrent du fichier initial', $this->display());
    }

    public function testAnOptionConflictAndAnInvalidRunNameAreRefusedWithoutWriting(): void
    {
        $before = $this->dataset->adoption->tableHashes();

        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--check' => true]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => str_repeat('a', 65)]));
        $this->assertSame(Command::INVALID, $this->tester->execute(['--apply' => true, '--source' => 'un lancement']));
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
    }

    public function testCheckWithStopBeforeHandoverJudgesOnlyTheClassificationAndTheSeeding(): void
    {
        // Given : rien n'est fait
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--check' => true, '--stop-before-handover' => true]), 'le classement n\'est pas posé');

        // When : le classement et l'amorçage sont faits, la passation non
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->display());

        // Then : conforme pour les phases 1 et 2, pas pour la passation entière
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true, '--stop-before-handover' => true]), $this->display());
        $this->assertStringContainsString('Rien à faire : classement posé, plus aucun compte à amorcer.', $this->display());
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--check' => true]));
        $this->assertStringContainsString('la passation n\'est pas faite', $this->display());
    }

    public function testTheDefaultRunNameIsDatedAndWrittenInEveryAlias(): void
    {
        // When
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);

        // Then
        $sources = $this->connection()->fetchFirstColumn('SELECT DISTINCT source FROM ingredient_alias WHERE user_id = ?', [$this->dataset->emptyId]);
        $this->assertCount(1, $sources);
        $this->assertMatchesRegularExpression('/^rattrapage-\\d{4}-\\d{2}-\\d{2}$/', $sources[0]);
    }

    public function testWritingIsRefusedOutsideTheAllowedEnvironmentsButReadingStaysPermitted(): void
    {
        // Given : la même commande, dans un environnement qui n'est pas prod ni dev
        $container = static::getContainer();
        $command = new HandOverCatalogIngredientsCommand(
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
        $dryRun = $tester->execute(['--stop-before-handover' => true, '--accept-sent' => true]);
        $check = $tester->execute(['--check' => true]);

        // Then : la lecture reste permise
        $this->assertSame(Command::INVALID, $apply);
        $this->assertStringContainsString('Écriture refusée dans l\'environnement « staging »', $applyOutput);
        $this->assertSame(Command::SUCCESS, $dryRun);
        $this->assertSame(Command::FAILURE, $check);
        $this->assertSame($before, $this->dataset->adoption->tableHashes());
    }

    /**
     * La sortie de la commande, espaces et retours à la ligne ramenés à une espace : un bloc d'erreur est coupé à la largeur du terminal.
     */
    private function display(): string
    {
        return preg_replace('/\\s+/', ' ', $this->tester->getDisplay());
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->dataset->connection;
    }

    private function withoutCreator(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL');
    }

    private function creatorsHash(): string
    {
        return (string) $this->connection()->fetchOne("SELECT md5(string_agg(slug || '=' || coalesce(creator, '∅'), ',' ORDER BY slug COLLATE \"C\")) FROM ingredient");
    }

    /**
     * @return list<string> les slugs « de base » du fichier, triés
     */
    private function fileBase(): array
    {
        $base = array_values(array_filter($this->dataset->slugs, fn(string $slug): bool => $this->dataset->classification[$slug]));
        sort($base, SORT_STRING);

        return $base;
    }

    /**
     * L'amorçage automatique à l'activation, allumé (en test la variable le coupe, comme la production avant l'allumage) : avant la première
     * lecture du service.
     */
    private function switchSeedingOn(): void
    {
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
    }
}

<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Repas\Domain\Exception\IngredientCatalogException;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\Tests\Helper\SeedingMeasures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les filets de la passation du catalogue (ING-009) sur la vraie base : chaque précondition P1 à P7 qui manque arrête la commande AVANT toute
 * écriture (le message dit laquelle) et, relue DANS la transaction de la phase 4, empêche le changement de créateur ; un classement qui ne
 * correspond pas au fichier est refusé ; une faute au milieu annule ce qu'elle doit et pas plus ; un compte activé en cours de route est vu par
 * la vérification. Les fautes sont injectées par des déclencheurs PostgreSQL créés DANS la transaction du test (annulée avec lui).
 */
class HandOverCatalogFailuresTest extends DatabaseTestCase
{
    use SeedingMeasures;

    private HandoverDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = HandoverDataset::create($this->entityManager, new DataPersister(static::getContainer()), migratedAccounts: 2);
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
        // L'interrupteur est allumé (sauf pour le test de P4) : avant la première lecture du service
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature($this->name() !== 'testP4TheSwitchIsOffAndTheHandlerRefusesToo'));
    }

    /**
     * Pour chaque précondition qui ne peut pas être levée par l'amorçage : comment la faire manquer, et le code attendu dans le message.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unmetPreconditions(): iterable
    {
        yield 'P1 : le propriétaire est désactivé' => ['ownerDisabled', 'P1'];
        yield 'P1 : le propriétaire n\'est plus administrateur' => ['ownerNotAdministrator', 'P1'];
        yield 'P2 : un compte en attente a une ligne sur un ancien commun' => ['pendingAccountHasALine', 'P2'];
        yield 'P3 : un compte actif amorcé n\'a plus aucun ingrédient' => ['activeAccountWithoutIngredient', 'P3'];
        yield 'P5 : un ancien commun a une origine' => ['oldCommonWithAnOrigin', 'P5'];
        yield 'P6 : une référence orpheline' => ['orphanReference', 'P6'];
        yield 'P7 : une ligne du propriétaire sur l\'ingrédient personnel d\'un autre compte' => ['ownerLineOnSomeoneElsesIngredient', 'P7'];
    }

    #[DataProvider('unmetPreconditions')]
    public function testAMissingPreconditionStopsTheFullApplyBeforeAnyWriteAndNamesItself(string $break, string $code): void
    {
        // Given
        $this->$break();
        $before = $this->dataset->hashesWithoutCreator();
        $creators = $this->creatorsHash();

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $output = $this->display();

        // Then : refusée avant toute écriture (ni classement, ni amorçage, ni passation), la sortie dit laquelle manque
        $this->assertSame(Command::FAILURE, $status, $output);
        $this->assertStringContainsString($code . ' :', $output);
        $this->assertStringContainsString('Rien n\'a été modifié.', $output);
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame($creators, $this->creatorsHash());
        $this->assertSame(261, $this->withoutCreator());
    }

    #[DataProvider('unmetPreconditions')]
    public function testAMissingPreconditionRelitInTheTransactionPreventsTheHandOver(string $break, string $code): void
    {
        // Given : le classement et l'amorçage sont faits (ce que fait --stop-before-handover), PUIS la condition manque, comme si elle avait
        // changé entre le contrôle de la commande et sa transaction
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->display());
        $this->$break();
        $before = $this->dataset->hashesWithoutCreator();
        $statistics = $this->ownerStatistics();

        // When : la commande de la phase 4, directement
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new HandOverCatalogCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            // Then : le message dit laquelle manque, aucun ingrédient n'a changé de créateur, rien n'est annoncé
            $this->assertStringContainsString($code . ' :', $e->getMessage());
            $this->assertStringContainsString("rien n'est écrit", $e->getMessage());
            $this->assertStringNotContainsString($this->dataset->ownerId, $e->getMessage());
        }
        $this->assertSame(261, $this->withoutCreator());
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame($statistics, $this->ownerStatistics());
    }

    public function testP2AMigratedAccountsLineOnAnOldCommonIsAlsoRefusedAndNamedByTheHandler(): void
    {
        // Given : un vieil onglet remet une ligne de recette d'un compte migré sur l'ancien commun
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $migrated = $this->dataset->migratedIds[0];
        $this->dataset->adoption->recipe($migrated, 'Recette de retard', ['farine']);

        // When / Then
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new HandOverCatalogCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            $this->assertStringContainsString('P2 : aucune ligne d\'un autre compte ne désigne un ingrédient sans créateur — recettes : 1 (dans 1 compte(s))', $e->getMessage());
        }
        $this->assertSame(261, $this->withoutCreator());
    }

    public function testP3AnActiveAccountThatIsNotSeededIsRefusedByTheHandlerButServedByTheFullApplyFirst(): void
    {
        // Given : un compte actif tout neuf, sans amorçage (activé interrupteur coupé, par exemple), après le classement
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $fresh = new UserBuilder()->build();
        new DataPersister(static::getContainer())->saveUser($fresh);

        // When : la phase 4 seule le refuse…
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new HandOverCatalogCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            $this->assertStringContainsString('P3 : tout compte actif, hors propriétaire, est amorcé et a des ingrédients — 1 compte(s) actif(s) sans amorçage', $e->getMessage());
        }
        $this->assertSame(261, $this->withoutCreator());

        // …et la commande complète sert d'abord ce compte (phase 2), puis passe
        $status = $this->tester->execute(['--apply' => true]);
        $this->assertSame(Command::SUCCESS, $status, $this->display());
        $this->assertCount(151, $this->copiesOf($fresh->getId()));
        $this->assertSame(0, $this->withoutCreator());
    }

    public function testP4TheSwitchIsOffAndTheHandlerRefusesToo(): void
    {
        // Given : l'interrupteur est coupé (jeu de départ de ce test), le classement et l'amorçage sont faits
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);

        // When / Then
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new HandOverCatalogCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            $this->assertStringContainsString('P4 : l\'amorçage à l\'activation est allumé (INGREDIENT_SEEDING_ENABLED) — il est coupé', $e->getMessage());
        }
        $this->assertSame(261, $this->withoutCreator());
    }

    public function testAnOldCommonThatIsNotInTheFileIsABlockingAnomalyAndTheClassificationRefusesToWrite(): void
    {
        // Given : un commun recréé par un ancien code (le robinet rouvert), inconnu du fichier
        $this->dataset->adoption->insert('ingredient', ['slug' => 'commun-recree', 'name' => 'Commun recree', 'image' => '', 'slugged_name' => 'commun-recree', 'department' => 'sauce', 'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => null, 'compatible_units' => '[]', 'adopted_from' => null]);
        $before = $this->dataset->hashesWithoutCreator();

        // When : la commande
        $dryRun = $this->tester->execute([]);
        $dryRunOutput = $this->display();
        $apply = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : le rapport nomme l'écart, l'écriture est refusée, rien n'est écrit
        $this->assertSame(Command::FAILURE, $dryRun);
        $this->assertStringContainsString('ANOMALIE BLOQUANTE : le classement ne correspond pas aux ingrédients sans créateur : 1 ne sont pas dans le fichier, 0 du fichier n\'y sont pas', $dryRunOutput);
        $this->assertStringContainsString('exemples hors fichier : commun-recree', $dryRunOutput);
        $this->assertSame(Command::FAILURE, $apply);
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient WHERE is_base'));

        // Et le cas d'usage de la phase 1, appelé directement (hors de la commande), refuse aussi, sans identifiant dans son message
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new ClassifyBaseIngredientsCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            $this->assertStringContainsString('1 ne sont pas dans le fichier, 0 du fichier n\'y sont pas', $e->getMessage());
            $this->assertStringNotContainsString('commun-recree', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient WHERE is_base'));
    }

    public function testAnOldCommonOfTheFileThatIsMissingIsRefusedToo(): void
    {
        // Given : « farine » a été supprimé de la base (un ancien code, une main)
        $this->connection()->executeStatement("DELETE FROM recipe_row WHERE ingredient = 'farine'");
        $this->connection()->executeStatement("DELETE FROM conversion WHERE ingredient = 'farine'");
        $this->connection()->executeStatement("DELETE FROM ingredient WHERE slug = 'farine'");
        $before = $this->dataset->hashesWithoutCreator();

        // When
        $apply = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then
        $this->assertSame(Command::FAILURE, $apply);
        $this->assertStringContainsString('0 ne sont pas dans le fichier, 1 du fichier n\'y sont pas', $this->display());
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--check' => true]));
    }

    public function testAFailureWhileSeedingTheEmptyAccountStopsTheRunAndKeepsTheClassification(): void
    {
        // Given : une faute à la copie des conversions du compte vide (après ses alias et ses copies)
        $connection = $this->connection();
        $emptyId = $this->dataset->emptyId;
        $connection->executeStatement(
            "CREATE FUNCTION test_boom() RETURNS trigger AS \$\$ BEGIN IF NEW.ingredient LIKE '%" . $emptyId . "' THEN RAISE EXCEPTION 'boom sur %', NEW.ingredient; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $connection->executeStatement('CREATE TRIGGER test_boom BEFORE INSERT ON conversion FOR EACH ROW EXECUTE FUNCTION test_boom()');
        $emptyBefore = $this->dataset->adoption->hashOfUser($emptyId);

        // When
        $status = $this->tester->execute(['--apply' => true, '--accept-sent' => true]);
        $output = $this->display();

        // Then : arrêt au premier échec, le compte vide n'a rien reçu (ni copie, ni alias, ni ligne, ni version), le message n'a aucun identifiant ;
        // le classement, lui, était déjà posé et le reste ; aucun ingrédient n'a changé de créateur
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit pour lui', $output);
        $this->assertStringContainsString('boom sur', $output);
        $this->assertStringContainsString('<id>', $output);
        $this->assertStringNotContainsString($emptyId, $output);
        $this->assertStringContainsString('Arrêt au premier échec', $output);
        $this->assertSame($emptyBefore, $this->dataset->adoption->hashOfUser($emptyId));
        $this->assertSame(151, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL AND is_base'));
        $this->assertSame(261, $this->withoutCreator());

        // Et la faute levée, on relance : tout se termine (sans danger)
        $connection->executeStatement('DROP TRIGGER test_boom ON conversion');
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--accept-sent' => true]), $this->display());
        $this->assertSame(0, $this->withoutCreator());
        $this->assertCount(156, $this->copiesOf($emptyId));
    }

    public function testAnErrorInTheHandOverRollsBackTheWholeUpdateAndAnnouncesNothing(): void
    {
        // Given : le classement et l'amorçage sont faits ; une faute sur le centième ingrédient passé
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $connection = $this->connection();
        $hundredth = (string) $connection->fetchOne('SELECT slug FROM ingredient WHERE adopted_from IS NULL AND creator IS NULL ORDER BY slug COLLATE "C" OFFSET 99 LIMIT 1');
        $connection->executeStatement(
            "CREATE FUNCTION test_boom() RETURNS trigger AS \$\$ BEGIN IF NEW.slug = '$hundredth' THEN RAISE EXCEPTION 'boom a la passation'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $connection->executeStatement('CREATE TRIGGER test_boom BEFORE UPDATE ON ingredient FOR EACH ROW WHEN (OLD.creator IS NULL AND NEW.creator IS NOT NULL) EXECUTE FUNCTION test_boom()');
        $before = $this->dataset->hashesWithoutCreator();
        $creators = $this->creatorsHash();
        $statistics = $this->ownerStatistics();

        // When
        $status = $this->tester->execute(['--apply' => true]);
        $output = $this->display();

        // Then : l'UPDATE entier est annulé (aucun des 261 n'a changé de créateur), la statistique n'est pas annoncée, la commande dit ÉCHEC
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Phase 4 (passation) : ÉCHEC.', $output);
        $this->assertStringContainsString('boom a la passation', $output);
        $this->assertStringContainsString('Rien n\'est écrit pour la passation (transaction annulée)', $output);
        $this->assertSame($creators, $this->creatorsHash());
        $this->assertSame(261, $this->withoutCreator());
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
        $this->assertSame($statistics, $this->ownerStatistics());

        // La faute levée, on relance et la passation se fait (une fois la statistique annoncée)
        $connection->executeStatement('DROP TRIGGER test_boom ON ingredient');
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->display());
        $this->assertSame(0, $this->withoutCreator());
        $this->assertSame(($statistics['ingredients'] ?? 0) + 261, $this->ownerStatistics()['ingredients']);
    }

    public function testAFingerprintThatDivergesDuringTheHandOverRollsEverythingBack(): void
    {
        // Given : une faute qui change le NOM d'un ingrédient quand son créateur change (l'empreinte des ingrédients diverge)
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $connection = $this->connection();
        $connection->executeStatement("CREATE FUNCTION test_corrupt() RETURNS trigger AS \$\$ BEGIN NEW.name := NEW.name || ' corrompu'; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        $connection->executeStatement('CREATE TRIGGER test_corrupt BEFORE UPDATE ON ingredient FOR EACH ROW WHEN (OLD.creator IS NULL AND NEW.creator IS NOT NULL) EXECUTE FUNCTION test_corrupt()');
        $before = $this->dataset->hashesWithoutCreator();

        // When
        $status = $this->tester->execute(['--apply' => true]);

        // Then
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('le contenu des ingrédients ou de leurs conversions propres a changé', $this->display());
        $this->assertSame(261, $this->withoutCreator());
        $this->assertSame($before, $this->dataset->hashesWithoutCreator());
    }

    public function testAVersionThatMovesDuringTheHandOverRollsEverythingBack(): void
    {
        // Given : une faute qui fait monter la version des recettes du propriétaire pendant la passation
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $connection = $this->connection();
        $connection->executeStatement("CREATE FUNCTION test_bump() RETURNS trigger AS \$\$ BEGIN UPDATE recipe SET version = version + 1 WHERE author = '" . $this->dataset->ownerId . "'; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        $connection->executeStatement('CREATE TRIGGER test_bump AFTER UPDATE ON ingredient FOR EACH ROW WHEN (OLD.creator IS NULL AND NEW.creator IS NOT NULL) EXECUTE FUNCTION test_bump()');
        $versions = $this->versionsOf($this->dataset->ownerId);

        // When
        $status = $this->tester->execute(['--apply' => true]);

        // Then : l'empreinte du propriétaire le voit, tout est annulé
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('la version de', $this->display());
        $this->assertSame(261, $this->withoutCreator());
        $this->assertSame($versions, $this->versionsOf($this->dataset->ownerId));
    }

    public function testAnAccountActivatedDuringTheHandOverWithoutSeedingIsSeenByTheFinalVerification(): void
    {
        // Given : pendant la passation, un compte en attente devient actif sans passer par l'amorçage (interrupteur coupé, chemin oublié)
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $connection = $this->connection();
        $connection->executeStatement("CREATE FUNCTION test_activate() RETURNS trigger AS \$\$ BEGIN UPDATE \"user\" SET status = 'ACTIVE' WHERE id = '" . $this->dataset->pendingId . "'; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        $connection->executeStatement('CREATE TRIGGER test_activate AFTER UPDATE ON ingredient FOR EACH ROW WHEN (OLD.creator IS NULL AND NEW.creator IS NOT NULL) EXECUTE FUNCTION test_activate()');

        // When
        $status = $this->tester->execute(['--apply' => true]);
        $output = $this->display();

        // Then : la passation est faite, mais la vérification de la phase 5 voit un compte actif sans amorçage et la commande échoue
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(0, $this->withoutCreator());
        $this->assertStringContainsString('il reste quelque chose à amorcer', $output);
        $this->assertStringContainsString('comptes actifs sans amorçage (hors propriétaire) : 1', $output);

        // Un rattrapage le sert, et la commande le dit enfin conforme
        $connection->executeStatement('DROP TRIGGER test_activate ON ingredient');
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->display());
        $this->assertCount(151, $this->copiesOf($this->dataset->pendingId));
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--check' => true]));
    }

    public function testAnActivationBeforeAndAfterTheHandOverGivesTheSameIngredients(): void
    {
        // Given : le classement et l'amorçage sont faits ; un compte en attente est activé AVANT la passation (la base a encore des ingrédients
        // sans créateur), un compte désactivé APRÈS (ils sont au propriétaire)
        $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]);
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $before = $this->activate($this->dataset->pendingId);
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->display());
        $after = $this->activate($this->dataset->disabledId);

        // Then : les mêmes 151 ingrédients « de base », dans les deux formes de la base
        $this->assertCount(151, $before);
        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertSame(0, $this->withoutCreator());
        unset($bus);
    }

    /**
     * Active un compte par le vrai cas d'usage (l'écouteur de l'amorçage, interrupteur allumé) et rend ses copies.
     *
     * @return array<string, string>
     */
    private function activate(string $userId): array
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new \Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand($userId, \Repas\User\Domain\Model\UserStatus::ACTIVE));

        return $this->copiesOf($userId);
    }

    // ---------------------------------------------------------------------------------------------------------------------------------
    // Comment faire manquer une précondition
    // ---------------------------------------------------------------------------------------------------------------------------------

    private function ownerDisabled(): void
    {
        $this->connection()->executeStatement("UPDATE \"user\" SET status = 'DISABLED' WHERE id = ?", [$this->dataset->ownerId]);
    }

    private function ownerNotAdministrator(): void
    {
        $this->connection()->executeStatement("UPDATE \"user\" SET roles = '[\"ROLE_USER\"]' WHERE id = ?", [$this->dataset->ownerId]);
    }

    private function pendingAccountHasALine(): void
    {
        // Un compte en attente n'est pas servi par l'amorçage : sa ligne reste sur l'ancien commun
        $this->dataset->adoption->recipe($this->dataset->pendingId, 'Recette en attente', ['farine']);
    }

    private function activeAccountWithoutIngredient(): void
    {
        // Un compte actif « amorcé » (un alias d'adoption) qui n'a plus aucune copie : l'amorçage ne le sert pas, la passation le refuse
        $user = new UserBuilder()->withId('c0000000-0000-4000-8000-0000000000c0')->build();
        new DataPersister(static::getContainer())->saveUser($user);
        $this->connection()->insert('ingredient_alias', ['user_id' => $user->getId(), 'alias_slug' => 'farine', 'ingredient_slug' => 'farine' . $user->getId(), 'kind' => 'adoption', 'source' => 'essai', 'created_at' => '2026-10-20 10:00:00']);
    }

    private function oldCommonWithAnOrigin(): void
    {
        $this->connection()->executeStatement("UPDATE ingredient SET adopted_from = 'quelque-chose' WHERE slug = 'farine'");
    }

    private function orphanReference(): void
    {
        $recipe = (string) $this->connection()->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$this->dataset->ownerId]);
        $this->connection()->insert('recipe_row', ['id' => UuidGenerator::new(), 'ingredient' => 'ingredient-fantome', 'quantity' => 1.0, 'unit' => 'gramme', 'recipe' => $recipe, 'position' => 99]);
    }

    private function ownerLineOnSomeoneElsesIngredient(): void
    {
        $personal = $this->dataset->personalSlugs[$this->dataset->migratedIds[0]][0];
        $recipe = (string) $this->connection()->fetchOne('SELECT id FROM recipe WHERE author = ? LIMIT 1', [$this->dataset->ownerId]);
        $this->connection()->insert('recipe_row', ['id' => UuidGenerator::new(), 'ingredient' => $personal, 'quantity' => 1.0, 'unit' => 'gramme', 'recipe' => $recipe, 'position' => 99]);
    }

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
     * @return array<string, mixed>
     */
    private function ownerStatistics(): array
    {
        return json_decode((string) $this->connection()->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$this->dataset->ownerId]), true);
    }
}

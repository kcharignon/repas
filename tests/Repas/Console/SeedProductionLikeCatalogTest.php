<?php

namespace Repas\Tests\Repas\Console;


use DateTimeImmutable;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedOutcome;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedResult;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Loader\IngredientDuplicateLoader;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Infrastructure\Console\SeedIngredientsCommand;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Repas\Domain\Interface\IngredientSeedingFeature;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\PerformanceReport;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * L'amorçage sur un catalogue de la taille de la production (ING-008) : les 261 anciens communs du fichier validé (aujourd'hui les ingrédients du propriétaire)
 * `docs/ingredients/base-ingredients.tsv`, dont 151 « de base » et 110 « spécifiques », avec une centaine de conversions propres (le fichier
 * est la donnée réelle de la passation ; ni nom d'ingrédient personnel ni identifiant ne sont en jeu). Un compte reçoit les 151 et seulement
 * eux,, pour le même nombre de requêtes que 5 ingrédients.
 *
 * Plus le découpage du catalogue (ADR 0038, SUB-002) : « Œuf » et ses deux parties, « de base » : un compte neuf a les trois copies reliées entre
 * elles, un compte amorcé avant le découpage est rattrapé par `--complete`, un compte qui a déjà son jaune d'œuf garde son lien.
 */
class SeedProductionLikeCatalogTest extends DatabaseTestCase
{
    private const string FILE = '/docs/ingredients/base-ingredients.tsv';

    /**
     * Les deux PARTIES d'« Œuf » ajoutées au fichier après la passation (ADR 0038, SUB-001) : des données de développement, pas des anciens communs.
     * Elles ne sont pas dans les 261 ni dans les sommes de contrôle de la passation, que ce test rejoue sur le catalogue d'alors.
     */
    private const array PARTS = ['jaune-d-oeuf', 'blanc-d-oeuf'];

    /** @var list<array{slug: string, name: string, department: string, base: bool}> */
    private array $rows = [];
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rows = self::classification();
        $persister = new DataPersister(static::getContainer());
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $persister->saveUser($owner);
        $this->ownerId = $owner->getId();
        $connection = $this->entityManager->getConnection();
        // Les rayons et les unités que les ingrédients désignent, pour les lectures par l'API
        foreach (array_unique(array_column($this->rows, 'department')) as $department) {
            $connection->insert('department', ['slug' => $department, 'name' => $department, 'image' => '']);
        }
        foreach ([['gramme', 'gramme', 'g'], ['kilo', 'kilo', 'kg']] as [$slug, $name, $symbol]) {
            $connection->insert('unit', ['slug' => $slug, 'name' => $name, 'symbol' => $symbol]);
        }
        foreach ($this->rows as $index => $row) {
            $connection->insert('ingredient', [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'image' => 'images/' . $row['slug'] . '.png',
                'slugged_name' => $row['slug'],
                'department' => $row['department'],
                'default_cooking_unit' => 'gramme',
                'default_purchase_unit' => 'kilo',
                'creator' => $this->ownerId,
                'compatible_units' => '["gramme","kilo"]',
                'adopted_from' => null,
                'is_base' => $row['base'] ? 1 : 0,
            ]);
            // Une conversion propre pour un ingrédient sur trois, deux pour un sur douze
            foreach (range(0, $index % 12 === 0 ? 1 : ($index % 3 === 0 ? 0 : -1)) as $position) {
                $connection->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => 'unite', 'end_unit' => $position === 0 ? 'gramme' : 'kilo', 'coefficient' => 10.5 + $index, 'ingredient' => $row['slug']]);
            }
        }
    }

    public function testTheFileIsTheValidatedClassificationOf261OldCommonsWith151BaseOnes(): void
    {
        // Then : les nombres et les sommes de contrôle du document `sans-commun.md` § 5
        $slugs = array_column($this->rows, 'slug');
        $base = array_column(array_filter($this->rows, static fn(array $row): bool => $row['base']), 'slug');
        sort($slugs, SORT_STRING);
        sort($base, SORT_STRING);
        $this->assertCount(261, $this->rows);
        $this->assertSame(261, count(array_unique($slugs)));
        $this->assertCount(151, $base);
        $this->assertSame('a43e401aaf59b6e65bd1f433f7347c7b', md5(implode(',', $slugs)));
        $this->assertSame('3776a0ad5d1fbc427f9431656c14b7e2', md5(implode(',', $base)));
    }

    public function testAnAccountReceivesExactlyTheBaseIngredients(): void
    {
        $connection = $this->entityManager->getConnection();
        $base = array_column(array_filter($this->rows, static fn(array $row): bool => $row['base']), 'slug');
        sort($base, SORT_STRING);
        $expectedConversions = (int) $connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.is_base');

        $label = 'compte neuf';
        // Given
        $user = new UserBuilder()->build();
        new DataPersister(static::getContainer())->saveUser($user);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $started = hrtime(true);

        // When
        $result = static::getContainer()->get(CommandBusInterface::class)->dispatch(new SeedUserIngredientsCommand($user->getId(), 'activation'));
        $seconds = (hrtime(true) - $started) / 1e9;
        $queries = $counter->count();

        // Then : les 151, et seulement eux
        $this->assertInstanceOf(SeedResult::class, $result);
        $this->assertSame(SeedOutcome::SEEDED, $result->outcome, $label);
        $this->assertSame(151, $result->seeded, $label);
        $copies = $connection->fetchAllKeyValue("SELECT alias_slug, ingredient_slug FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption' ORDER BY alias_slug COLLATE \"C\"", [$user->getId()]);
        $this->assertSame($base, array_keys($copies), $label);
        $this->assertSame(151, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL AND NOT is_base', [$user->getId()]), $label);
        $this->assertSame(151, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$user->getId()]), $label);
        $this->assertSame($expectedConversions, $result->conversionsCopied, $label);
        $this->assertSame($expectedConversions, (int) $connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.creator = ?', [$user->getId()]), $label);
        // Chaque conversion propre de l'original est chez la copie, mêmes unités et même coefficient, avec un identifiant NEUF (rien n'est perdu à l'amorçage)
        $this->assertSame($expectedConversions, (int) $connection->fetchOne(
            'SELECT count(*) FROM conversion c JOIN ingredient copy ON copy.slug = c.ingredient JOIN conversion source ON source.ingredient = copy.adopted_from'
            . ' AND source.start_unit = c.start_unit AND source.end_unit = c.end_unit AND source.coefficient = c.coefficient AND source.id <> c.id WHERE copy.creator = ?',
            [$user->getId()],
        ), $label);
        // Les 110 « spécifiques » ne sont pas copiés
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IN (SELECT slug FROM ingredient WHERE NOT is_base)', [$user->getId()]), $label);
        // Le catalogue est intact : 261 ingrédients, 151 de base
        $this->assertSame(261, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE adopted_from IS NULL AND creator = ?', [$this->ownerId]), $label);
        // Le coût : constant, celui des scénarios de budget, quelle que soit la taille du catalogue ; et rapide
        $this->assertSame((require dirname(__DIR__) . '/WriteBudget/budgets.php')['amorcer-compte'], $queries, $label);
        $this->assertLessThan(PerformanceReport::allowed(2.0), $seconds, $label . ' : ' . round($seconds, 2) . ' s');
    }

    public function testARegisteredVisitorWhoConfirmsTheirAddressGetsTheHundredAndFiftyOneIngredientsAndSeesThemThroughTheApi(): void
    {
        // Given : l'interrupteur est allumé, un visiteur s'inscrit par l'API publique
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $client = static::getContainer()->get('test.client');
        $client->disableReboot();
        $json = ['CONTENT_TYPE' => 'application/json'];
        $client->request('POST', '/api/v1/users', content: json_encode(['email' => 'nouveau@example.test', 'defaultServing' => 3], JSON_THROW_ON_ERROR), server: $json);
        $this->assertSame(204, $client->getResponse()->getStatusCode());
        $connection = $this->entityManager->getConnection();
        $userId = (string) $connection->fetchOne('SELECT id FROM "user" WHERE email = ?', ['nouveau@example.test']);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$userId]), 'l\'inscription ne copie rien');
        $secret = bin2hex(random_bytes(16));
        static::getContainer()->get(PasswordResetTokenRepository::class)->add(PasswordResetToken::issueWelcome(UuidGenerator::new(), $userId, PasswordResetToken::hash($secret), new DateTimeImmutable()));

        // When : il choisit son mot de passe avec le lien de bienvenue, ce qui confirme son adresse
        $started = hrtime(true);
        $client->request('POST', '/api/v1/users/password-reset/confirm', content: json_encode(['token' => $secret, 'password' => 'un-mot-de-passe'], JSON_THROW_ON_ERROR), server: $json);
        $seconds = (hrtime(true) - $started) / 1e9;

        // Then : ses 151 ingrédients (et leurs conversions) sont là, dans la même requête, en moins d'une seconde
        $this->assertSame(204, $client->getResponse()->getStatusCode());
        $this->assertSame(151, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL AND NOT is_base', [$userId]));
        $this->assertSame(151, (int) $connection->fetchOne("SELECT count(*) FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption' AND source = 'activation'", [$userId]));
        $this->assertLessThan(PerformanceReport::allowed(1.0), $seconds, 'la confirmation, amorçage compris : ' . round($seconds, 2) . ' s');

        // …et il les voit par l'API : une fiche et un rayon entier, rien qui ne soit à lui
        $client->request('POST', '/api/login_check', content: json_encode(['email' => 'nouveau@example.test', 'password' => 'un-mot-de-passe'], JSON_THROW_ON_ERROR), server: $json);
        $jwt = json_decode($client->getResponse()->getContent(), true)['token'];
        $header = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt];
        $client->request('GET', '/api/v1/departments/fruit-et-legume/ingredients', server: $header);
        $members = json_decode($client->getResponse()->getContent(), true)['member'];
        $expected = (int) $connection->fetchOne("SELECT count(*) FROM ingredient WHERE creator = ? AND department = 'fruit-et-legume'", [$userId]);
        $this->assertGreaterThan(5, $expected);
        $this->assertCount($expected, $members, 'ses copies, et rien d\'autre : plus aucun ingrédient partagé');
        $mine = array_values(array_filter($members, static fn(array $member): bool => str_ends_with($member['slug'], $userId)));
        $this->assertCount($expected, $mine);
        $this->assertSame([true], array_values(array_unique(array_column($mine, 'canEdit'))));
        $this->assertSame([false], array_values(array_unique(array_column($mine, 'isBase'))));
        $client->request('GET', '/api/v1/ingredients/' . $mine[0]['slug'], server: $header);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }


    // --- Les sous-ingrédients (ADR 0038, SUB-002) : « Œuf » est de base, ses deux parties aussi

    public function testANewAccountReceivesTheEggAndItsTwoPartsLinkedToItsOwnEgg(): void
    {
        // Given : le propriétaire a découpé « Œuf » (ses deux parties sont « de base », ratio 1) ; un compte neuf
        $this->addEggParts();
        $user = $this->account('11111111-1111-4111-8111-111111111111');
        $connection = $this->entityManager->getConnection();
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->seed($user);
        $queries = $counter->count();

        // Then : les 153 copies, jamais un parent copié « pour compléter » ; les deux parties sont reliées à SA copie d'« Œuf »
        $this->assertSame(153, $result->seeded);
        $this->assertSame(2, $result->partsLinked);
        $egg = $this->copyOf('oeuf', $user);
        $this->assertSame([$egg, 1.0], $this->parentOf($this->copyOf('jaune-d-oeuf', $user)));
        $this->assertSame([$egg, 1.0], $this->parentOf($this->copyOf('blanc-d-oeuf', $user)));
        $this->assertSame([null, null], $this->parentOf($egg));
        $this->assertNotSame('oeuf', $egg, 'jamais le slug du catalogue');
        // Le catalogue est intact, et les 151 autres copies sont ordinaires
        $this->assertSame([null, null], $this->parentOf('oeuf'));
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune-d-oeuf'));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND parent_slug IS NOT NULL', [$user]));
        // Le coût : celui du scénario de budget, constant (une instruction de liaison de plus qu'avant, quel que soit le nombre de parties)
        $this->assertSame((require dirname(__DIR__) . '/WriteBudget/budgets.php')['amorcer-compte'], $queries);
    }

    public function testAnAccountSeededBeforeTheSplitIsCaughtUpByCompleteWhichCopiesTheMissingPartsThenLinksThem(): void
    {
        // Given : un compte amorcé quand « Œuf » n'avait pas de parties ; le propriétaire les crée ensuite (de base, reliées)
        $user = $this->account('22222222-2222-4222-8222-222222222222');
        $this->seed($user);
        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IN (?, ?)', [$user, 'jaune-d-oeuf', 'blanc-d-oeuf']));
        $this->addEggParts();
        $reference = IngredientSeedingReportPostgreSQLLoader::referenceOf($user);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));

        // When : le rapport à blanc d'abord, puis l'écriture
        $dryRun = $tester->execute(['--complete' => true, '--user' => [$reference]]);
        $dryRunOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());
        $apply = $tester->execute(['--apply' => true, '--complete' => true, '--user' => [$reference], '--source' => 'complement-parties']);
        $applyOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());

        // Then : l'à blanc annonce deux copies et deux liens (celles qui n'existent pas encore sont imaginées) ; l'écriture les fait
        $this->assertSame(Command::SUCCESS, $dryRun, $dryRunOutput);
        $this->assertStringContainsString('à copier : 2 ingrédient(s)', $dryRunOutput);
        $this->assertStringContainsString('à relier : 2 copie(s)', $dryRunOutput);
        $this->assertStringContainsString('2 lien(s) de sous-ingrédients à poser', $dryRunOutput);
        $this->assertSame(Command::SUCCESS, $apply, $applyOutput);
        $this->assertStringContainsString('2 lien(s) posé(s) entre sous-ingrédients', $applyOutput);
        $egg = $this->copyOf('oeuf', $user);
        $this->assertSame([$egg, 1.0], $this->parentOf($this->copyOf('jaune-d-oeuf', $user)));
        $this->assertSame([$egg, 1.0], $this->parentOf($this->copyOf('blanc-d-oeuf', $user)));
        // …et un second passage ne trouve plus rien
        $this->assertSame(Command::SUCCESS, $tester->execute(['--check' => true]));
    }

    public function testCopiesThatWaitForTheirLinkAreReportedByCheckAndLinkedByCompleteAlone(): void
    {
        // Given : le compte a reçu les trois copies quand les parties n'étaient pas encore reliées dans le catalogue ; le propriétaire les relie ensuite
        $this->addEggParts(linked: false);
        $user = $this->account('33333333-3333-4333-8333-333333333333');
        $result = $this->seed($user);
        $this->assertSame(0, $result->partsLinked);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE ingredient SET parent_slug = 'oeuf', parent_ratio = 1 WHERE slug IN ('jaune-d-oeuf', 'blanc-d-oeuf') AND creator = ?", [$this->ownerId]);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));

        // When : --check (la requête de santé)
        $check = $tester->execute(['--check' => true]);
        $checkOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());

        // Then : rien à écrire pour la sélection, mais la santé n'est pas verte : deux copies attendent leur lien
        $this->assertSame(SeedIngredientsCommand::EXIT_HEALTH_RED, $check, $checkOutput);
        $this->assertStringContainsString('copies à relier (sous-ingrédients, tous comptes) : 2', $checkOutput);
        $this->assertStringContainsString('verdict : ROUGE', $checkOutput);

        // When : --complete sur ce compte, sans rien à copier
        $apply = $tester->execute(['--apply' => true, '--complete' => true, '--user' => [IngredientSeedingReportPostgreSQLLoader::referenceOf($user)]]);
        $applyOutput = preg_replace('/\\s+/', ' ', $tester->getDisplay());

        // Then : les deux liens sont posés, sans aucune copie de plus, et la santé est verte
        $this->assertSame(Command::SUCCESS, $apply, $applyOutput);
        $this->assertStringContainsString('0 copie(s) créée(s)', $applyOutput);
        $this->assertStringContainsString('2 lien(s) posé(s) entre sous-ingrédients', $applyOutput);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--check' => true]), preg_replace('/\\s+/', ' ', $tester->getDisplay()));
        $this->assertSame(153, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$user]));
    }

    public function testAnAccountThatAlreadyHasItsOwnYolkGetsTheCopyAndTheLinkItMadeIsNeverOverwritten(): void
    {
        // Given : le compte a SON jaune d'œuf, qu'il a lui-même découpé comme partie d'un « Œuf extra » à lui (ratio 3)
        $this->addEggParts();
        $user = $this->account('44444444-4444-4444-8444-444444444444');
        $connection = $this->entityManager->getConnection();
        foreach ([['oeuf-extra', "Œuf extra", null, null], ['jaune-d-oeuf', "Jaune d'œuf", 'oeuf-extra' . $user, 3.0]] as [$slug, $name, $parent, $ratio]) {
            $connection->insert('ingredient', [
                'slug' => $slug . $user, 'name' => $name, 'image' => '', 'slugged_name' => $slug, 'department' => 'divers', 'default_cooking_unit' => 'gramme',
                'default_purchase_unit' => 'kilo', 'creator' => $user, 'compatible_units' => '["gramme","kilo"]', 'adopted_from' => null, 'is_base' => 0,
                'parent_slug' => $parent, 'parent_ratio' => $ratio,
            ]);
        }
        $this->entityManager->clear();

        // When : l'amorçage
        $result = $this->seed($user);

        // Then : il reçoit la copie du jaune du catalogue (un slug suffixé), reliée à la copie d'« Œuf » ; son propre lien n'a pas bougé
        $this->assertSame(2, $result->partsLinked, 'les copies du jaune et du blanc du catalogue sont reliées, pas le jaune du compte');
        $copy = $this->copyOf('jaune-d-oeuf', $user);
        $this->assertNotSame('jaune-d-oeuf' . $user, $copy);
        $this->assertSame([$this->copyOf('oeuf', $user), 1.0], $this->parentOf($copy));
        $this->assertSame(['oeuf-extra' . $user, 3.0], $this->parentOf('jaune-d-oeuf' . $user));

        // And : « Doublons possibles » propose de fusionner les deux jaunes
        $pairs = static::getContainer()->get(IngredientDuplicateLoader::class)->duplicates($user);
        $slugs = array_map(static fn(DuplicateIngredientDTO $pair): array => [$pair->first->slug, $pair->second->slug], $pairs);
        $expected = [$copy, 'jaune-d-oeuf' . $user];
        sort($expected, SORT_STRING);
        $this->assertContains($expected, $slugs);
    }

    public function testTheCatalogOwnerAndAnotherAccountAreNeverTouchedByAnAccountsLinks(): void
    {
        // Given : un compte déjà amorcé, un autre compte à amorcer
        $this->addEggParts();
        $first = $this->account('55555555-5555-4555-8555-555555555555');
        $second = $this->account('66666666-6666-4666-8666-666666666666');
        $this->seed($first);
        $connection = $this->entityManager->getConnection();
        $beforeOwner = $connection->fetchAllAssociative('SELECT slug, parent_slug, parent_ratio FROM ingredient WHERE creator = ? ORDER BY slug', [$this->ownerId]);
        $beforeFirst = $connection->fetchAllAssociative('SELECT slug, parent_slug, parent_ratio FROM ingredient WHERE creator = ? ORDER BY slug', [$first]);

        // When
        $this->seed($second);

        // Then
        $this->assertSame($beforeOwner, $connection->fetchAllAssociative('SELECT slug, parent_slug, parent_ratio FROM ingredient WHERE creator = ? ORDER BY slug', [$this->ownerId]));
        $this->assertSame($beforeFirst, $connection->fetchAllAssociative('SELECT slug, parent_slug, parent_ratio FROM ingredient WHERE creator = ? ORDER BY slug', [$first]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND parent_slug IN (SELECT slug FROM ingredient WHERE creator <> ?)', [$second, $second]));
    }

    // --- Aides des sous-ingrédients

    /**
     * Les deux parties d'« Œuf » du fichier validé (ADR 0038 décision 32), créées par le propriétaire : « de base », dans le rayon d'« Œuf », ratio 1.
     */
    private function addEggParts(bool $linked = true): void
    {
        foreach (self::PARTS as $slug) {
            $this->entityManager->getConnection()->insert('ingredient', [
                'slug' => $slug, 'name' => $slug === 'jaune-d-oeuf' ? "Jaune d'œuf" : "Blanc d'œuf", 'image' => '', 'slugged_name' => $slug, 'department' => 'divers',
                'default_cooking_unit' => 'gramme', 'default_purchase_unit' => 'kilo', 'creator' => $this->ownerId, 'compatible_units' => '["gramme","kilo"]',
                'adopted_from' => null, 'is_base' => 1, 'parent_slug' => $linked ? 'oeuf' : null, 'parent_ratio' => $linked ? 1 : null,
            ]);
        }
    }

    private function account(string $id): string
    {
        new DataPersister(static::getContainer())->saveUser(new UserBuilder()->withId($id)->withEmail($id . '@example.test')->build());

        return $id;
    }

    private function seed(string $userId): SeedResult
    {
        $result = static::getContainer()->get(CommandBusInterface::class)->dispatch(new SeedUserIngredientsCommand($userId, 'activation'));
        $this->assertInstanceOf(SeedResult::class, $result);

        return $result;
    }

    /**
     * Le slug de la copie qu'un compte a reçue d'un ingrédient du catalogue (par l'alias d'adoption).
     */
    private function copyOf(string $catalogSlug, string $userId): string
    {
        $slug = $this->entityManager->getConnection()->fetchOne('SELECT slug FROM ingredient WHERE creator = ? AND adopted_from = ?', [$userId, $catalogSlug]);
        $this->assertIsString($slug, $catalogSlug);

        return $slug;
    }

    /**
     * @return array{0: ?string, 1: ?float}
     */
    private function parentOf(string $slug): array
    {
        $row = $this->entityManager->getConnection()->fetchNumeric('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$slug]);
        $this->assertNotFalse($row, $slug);

        return [$row[0], $row[1] === null ? null : (float) $row[1]];
    }

    /**
     * @return list<array{slug: string, name: string, department: string, base: bool}>
     */
    private static function classification(): array
    {
        $rows = [];
        foreach (array_slice(file(dirname(__DIR__, 3) . self::FILE, FILE_IGNORE_NEW_LINES), 1) as $line) {
            [$slug, $name, $department, $base] = explode("\t", $line);
            if (in_array($slug, self::PARTS, true)) {
                continue;
            }
            $rows[] = ['slug' => $slug, 'name' => $name, 'department' => $department, 'base' => $base === '1'];
        }

        return $rows;
    }
}

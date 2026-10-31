<?php

namespace Repas\Tests\Command;


use DateTimeImmutable;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedOutcome;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedResult;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
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

/**
 * L'amorçage sur un catalogue de la taille de la production (ING-008) : les 261 anciens communs du fichier validé (aujourd'hui les ingrédients du propriétaire)
 * `docs/ingredients/base-ingredients.tsv`, dont 151 « de base » et 110 « spécifiques », avec une centaine de conversions propres (le fichier
 * est la donnée réelle de la passation ; ni nom d'ingrédient personnel ni identifiant ne sont en jeu). Un compte reçoit les 151 et seulement
 * eux,, pour le même nombre de requêtes que 5 ingrédients.
 */
class SeedProductionLikeCatalogTest extends DatabaseTestCase
{
    private const string FILE = '/docs/ingredients/base-ingredients.tsv';

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
        $this->assertSame((require dirname(__DIR__) . '/Repas/WriteBudget/budgets.php')['amorcer-compte'], $queries, $label);
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

    /**
     * @return list<array{slug: string, name: string, department: string, base: bool}>
     */
    private static function classification(): array
    {
        $rows = [];
        foreach (array_slice(file(dirname(__DIR__, 2) . self::FILE, FILE_IGNORE_NEW_LINES), 1) as $line) {
            [$slug, $name, $department, $base] = explode("\t", $line);
            $rows[] = ['slug' => $slug, 'name' => $name, 'department' => $department, 'base' => $base === '1'];
        }

        return $rows;
    }
}

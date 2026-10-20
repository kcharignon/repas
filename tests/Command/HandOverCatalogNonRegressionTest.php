<?php

namespace Repas\Tests\Command;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\HandoverDataset;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Non-régression de la passation (ING-009, ADR 0017 règle 6) : le code déployé AVANT (R1, la base d'aujourd'hui), PENDANT (le classement posé, le
 * compte vide amorcé) et APRÈS la passation fait la même chose pour le propriétaire, un compte migré et l'ancien compte vide : lire les rayons,
 * chercher, ouvrir un ingrédient, écrire une recette, planifier et retirer un repas, lire sa liste de courses, copier la recette d'un autre,
 * lister les doublons, aperçu de fusion, et amorcer un nouveau compte. Seule différence permise, et documentée : l'ancien compte vide ne voit plus
 * les « spécifiques » partagés qu'il voyait avant d'avoir ses propres ingrédients (décision D5 de l'amendement).
 *
 * Les vrais bus, la vraie base, le voter et les écouteurs ; les requêtes passent par l'API avec un JWT.
 */
class HandOverCatalogNonRegressionTest extends DatabaseTestCase
{
    private const string DEPARTMENT = 'fruit-et-legume';

    private KernelBrowser $client;
    private HandoverDataset $dataset;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        $persister = new DataPersister(static::getContainer());
        $persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->dataset = HandoverDataset::create($this->entityManager, $persister, migratedAccounts: 2);
        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
    }

    public function testEveryReadAndWriteWorksBeforeDuringAndAfterTheHandOver(): void
    {
        // Given : l'état d'aujourd'hui (F0) ; chaque compte de l'essai
        $owner = $this->user($this->dataset->ownerId);
        $migrated = $this->user($this->dataset->migratedIds[0]);
        $formerlyEmpty = $this->user($this->dataset->emptyId);
        $accounts = ['owner' => $owner, 'migrated' => $migrated, 'formerlyEmpty' => $formerlyEmpty];

        // AVANT
        $before = $this->observeAll($accounts, 'avant');

        // PENDANT : le classement posé et le compte vide amorcé, les anciens communs toujours sans créateur
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->tester->getDisplay());
        $during = $this->observeAll($accounts, 'pendant');

        // APRÈS : la passation
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->tester->getDisplay());
        $after = $this->observeAll($accounts, 'apres');

        // Then : le propriétaire et le compte migré voient et font EXACTEMENT la même chose aux trois moments (hors marqueur « de base », posé pendant)
        foreach (['owner', 'migrated'] as $who) {
            // (l'aperçu de fusion du propriétaire est la seule différence : voir plus bas)
            foreach (['all', 'department', 'search', 'ingredient', 'recipeRows', 'listRead', 'plannedRows', 'duplicates', 'unplannedRows'] as $point) {
                $this->assertEquals($before[$who][$point], $during[$who][$point], "$who : $point, avant/pendant");
                $this->assertEquals($during[$who][$point], $after[$who][$point], "$who : $point, pendant/après");
            }
            $this->assertEquals($before[$who]['statuses'], $during[$who]['statuses'], "$who : statuts, avant/pendant");
            $this->assertEquals($during[$who]['statuses'], $after[$who]['statuses'], "$who : statuts, pendant/après");
            $this->assertEquals($during[$who]['copyRecipeRows'] ?? null, $after[$who]['copyRecipeRows'] ?? null, "$who : recopie de recette, pendant/après");
        }
        // Le compte migré fusionne ses copies avant comme après ; le propriétaire ne le pouvait pas (un ancien commun n'est à personne) et le peut
        // désormais : ses ingrédients sont à lui, comme ceux de tout le monde (amendement du 2026-10-20, "plus de traitement particulier")
        $this->assertEquals($before['migrated']['mergePreview'], $after['migrated']['mergePreview']);
        $this->assertSame(200, $after['migrated']['statuses']['mergePreview']);
        $this->assertSame('COMMON_INGREDIENT', $before['owner']['mergePreview'][0]);
        $this->assertSame('COMMON_INGREDIENT', $during['owner']['mergePreview'][0]);
        $this->assertNull($after['owner']['mergePreview'][0]);
        $this->assertSame(200, $after['owner']['statuses']['mergePreview']);

        // Copier la recette du propriétaire : 201 à chaque moment pour les deux autres comptes, et elle se branche sur LEURS ingrédients sans en créer
        foreach (['migrated' => [$before, $during, $after], 'formerlyEmpty' => [$during, $after]] as $who => $states) {
            foreach ($states as $index => $state) {
                $this->assertSame(201, $state[$who]['statuses']['copy'], "$who : copie, état $index");
                $this->assertSame(0, $state[$who]['copyCreatedIngredients'], "$who : aucun ingrédient créé par la copie, état $index");
            }
        }
        $this->assertEquals($during['formerlyEmpty']['copyRecipeRows'], $after['formerlyEmpty']['copyRecipeRows']);

        // Le marqueur « de base » n'est montré qu'au propriétaire : faux partout avant le classement, vrai pour 151 de ses ingrédients ensuite
        $this->assertSame([], array_filter($before['owner']['bases']), 'avant le classement, aucun marqueur');
        $this->assertGreaterThan(5, count(array_filter($during['owner']['bases'])));
        $this->assertEquals($during['owner']['bases'], $after['owner']['bases']);
        foreach (['migrated', 'formerlyEmpty'] as $other) {
            foreach ([$before, $during, $after] as $state) {
                $this->assertSame([], array_filter($state[$other]['bases']), "$other ne voit jamais un marqueur");
            }
        }

        // Le propriétaire modifie ses ingrédients comme avant (canEdit) ; un compte migré aussi
        $this->assertSame([true], array_values(array_unique($before['owner']['canEdit'])));
        $this->assertSame([true], array_values(array_unique($after['owner']['canEdit'])));
        $this->assertSame([true], array_values(array_unique($after['migrated']['canEdit'])));

        // L'ancien compte vide : avant, il voit les 261 anciens communs partagés ; pendant (amorcé, rien ne change pour ce qu'il voit : ses copies et
        // les « spécifiques » encore partagés), les mêmes 261 ; APRÈS la passation, seulement ses 156 copies (les 105 « spécifiques » restent chez le
        // propriétaire : décision D5)
        $this->assertCount(261, $before['formerlyEmpty']['all']);
        $this->assertEquals($before['formerlyEmpty']['all'], $during['formerlyEmpty']['all']);
        $this->assertCount(156, $after['formerlyEmpty']['all']);
        $this->assertSame([], array_diff($after['formerlyEmpty']['all'], $during['formerlyEmpty']['all']));
        $this->assertContains('tomate-a-farcir', $during['formerlyEmpty']['search'], 'un « spécifique » encore partagé avant la passation');
        $this->assertNotContains('tomate-a-farcir', $after['formerlyEmpty']['search'], 'chez le propriétaire seulement après la passation');
        $this->assertSame([], array_diff($after['formerlyEmpty']['search'], $during['formerlyEmpty']['search']));
        $this->assertContains('tomate', $after['formerlyEmpty']['search']);
        $this->assertEquals($during['formerlyEmpty']['recipeRows'], $after['formerlyEmpty']['recipeRows']);
        $this->assertSame([], array_diff($after['formerlyEmpty']['department'], $during['formerlyEmpty']['department']), 'son rayon, après la passation, est celui de ses copies');
        $this->assertLessThan(count($during['formerlyEmpty']['department']), count($after['formerlyEmpty']['department']));
        $this->assertEquals($during['formerlyEmpty']['listRead'], $after['formerlyEmpty']['listRead']);
        $this->assertEquals($during['formerlyEmpty']['unplannedRows'], $after['formerlyEmpty']['unplannedRows']);
        $this->assertSame($during['formerlyEmpty']['statuses'], $after['formerlyEmpty']['statuses']);
        foreach ($after['formerlyEmpty']['statuses'] as $step => $status) {
            $this->assertLessThan(400, $status, "formerlyEmpty : $step après la passation");
        }

        // Aucun ingrédient sans créateur, aucune statistique du propriétaire autre que les 261
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
    }

    public function testANewAccountIsSeededWithTheSameIngredientsBeforeAndAfterTheHandOver(): void
    {
        // Given : le classement posé, un compte s'inscrit et confirme son adresse
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true, '--stop-before-handover' => true, '--accept-sent' => true]), $this->tester->getDisplay());
        $first = $this->registerAndConfirm('avant@example.test');

        // When : la passation, puis un autre compte s'inscrit et confirme
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--apply' => true]), $this->tester->getDisplay());
        $second = $this->registerAndConfirm('apres@example.test');

        // Then : les mêmes 151 ingrédients « de base » (par leur origine), avec leurs conversions propres, dans les deux formes de la base
        $connection = $this->dataset->connection;
        $origins = static fn(string $id): array => array_map('strval', $connection->fetchFirstColumn("SELECT alias_slug FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption' ORDER BY alias_slug COLLATE \"C\"", [$id]));
        $this->assertCount(151, $origins($first));
        $this->assertSame($origins($first), $origins($second));
        $conversions = static fn(string $id): int => (int) $connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.creator = ?', [$id]);
        $this->assertGreaterThan(0, $conversions($first));
        $this->assertSame($conversions($first), $conversions($second));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IN (?, ?) AND is_base', [$first, $second]), 'une copie reçue n\'est jamais « de base »');

        // …et il les voit par l'API
        $this->request('GET', '/api/v1/departments/' . self::DEPARTMENT . '/ingredients', $this->user($second));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $members = $this->jsonBody()['member'];
        $this->assertGreaterThan(5, count($members));
        $this->assertSame([$second], array_values(array_unique(array_map(static fn(array $member): string => substr($member['slug'], -36), $members))));
    }

    /**
     * Tout ce qu'un compte voit et fait, avec les statuts HTTP : de quoi comparer deux moments.
     *
     * @param array<string, User> $accounts
     *
     * @return array<string, array<string, mixed>>
     */
    private function observeAll(array $accounts, string $moment): array
    {
        $observed = [];
        $ownerRecipeId = null;
        foreach ($accounts as $who => $user) {
            $statuses = [];
            $data = [];

            // Rayon, recherche, ingrédient
            $this->request('GET', '/api/v1/departments/' . self::DEPARTMENT . '/ingredients', $user);
            $statuses['department'] = $this->httpStatus();
            $rows = $this->jsonBody()['member'] ?? [];
            $data['department'] = $this->relative($user, array_column($rows, 'slug'));
            $data['bases'] = array_column($rows, 'isBase', 'slug');
            $data['canEdit'] = array_column($rows, 'canEdit', 'slug');
            $this->request('GET', '/api/v1/ingredients', $user);
            $statuses['all'] = $this->httpStatus();
            $data['all'] = $this->relative($user, array_column($this->jsonBody()['member'] ?? [], 'slug'));
            $this->request('GET', '/api/v1/ingredients/search?q=tomate', $user);
            $statuses['search'] = $this->httpStatus();
            $data['search'] = $this->relative($user, array_column($this->jsonBody()['member'] ?? [], 'slug'));
            $first = (string) $rows[0]['slug'];
            $this->request('GET', '/api/v1/ingredients/' . $first, $user);
            $statuses['ingredient'] = $this->httpStatus();
            $data['ingredient'] = [$this->jsonBody()['name'] ?? null, $this->jsonBody()['canEdit'] ?? null];

            // Une recette qu'il écrit avec deux ingrédients qu'il voit, qu'il relit, planifie puis retire de sa liste
            $slugs = array_slice(array_column($rows, 'slug'), 0, 2);
            $this->request('POST', '/api/v1/recipes', $user, $this->recipeBody("Recette d'essai $who $moment", $slugs));
            $statuses['recipeCreated'] = $this->httpStatus();
            $recipeId = basename((string) $this->client->getResponse()->headers->get('Location'));
            $this->request('GET', '/api/v1/recipes/' . $recipeId, $user);
            $statuses['recipeRead'] = $this->httpStatus();
            $data['recipeRows'] = array_map(static fn(array $row): array => [$row['ingredientName'], $row['quantity'], $row['unitSlug']], $this->jsonBody()['rows'] ?? []);
            $listId = $this->activeListOf($user);
            $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $user);
            $statuses['plan'] = $this->httpStatus();
            $data['plannedRows'] = $this->listRows($listId, $recipeId);
            $this->request('GET', "/api/v1/shopping-lists/$listId", $user);
            $statuses['listRead'] = $this->httpStatus();
            $data['listRead'] = count($this->jsonBody()['rows'] ?? $this->jsonBody()['member'] ?? []) > 0 ? 'avec des lignes' : 'vide';
            $this->request('DELETE', '/api/v1/shopping-lists/active/recipes/' . $recipeId, $user);
            $statuses['unplan'] = $this->httpStatus();
            $data['unplannedRows'] = $this->listRows($listId, $recipeId);

            // Copier la recette du propriétaire (un autre compte la copie : elle se branche sur SES ingrédients, sans en créer)
            if ($who === 'owner') {
                $ownerRecipeId = $recipeId;
            } else {
                $ingredientsBefore = (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient');
                $this->request('POST', "/api/v1/recipes/$ownerRecipeId/copy", $user);
                $statuses['copy'] = $this->httpStatus();
                if ($statuses['copy'] === 201) {
                    $copied = basename((string) $this->client->getResponse()->headers->get('Location'));
                    $data['copyRecipeRows'] = $this->relative($user, $this->dataset->connection->fetchFirstColumn('SELECT ingredient FROM recipe_row WHERE recipe = ? ORDER BY position', [$copied]));
                    $data['copyCreatedIngredients'] = (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient') - $ingredientsBefore;
                }
            }

            // Doublons possibles et aperçu de fusion de deux de ses ingrédients voisins (« tomate » et « tomate cerise »)
            $this->request('GET', '/api/v1/ingredients/duplicates', $user);
            $statuses['duplicates'] = $this->httpStatus();
            $data['duplicates'] = count($this->jsonBody()['member'] ?? []);
            $tomato = $this->ownedSlug($user, 'tomate');
            $cherry = $this->ownedSlug($user, 'tomate-cerise');
            $this->request('GET', "/api/v1/ingredients/$cherry/merge-preview?into=$tomato", $user);
            $statuses['mergePreview'] = $this->httpStatus();
            $data['mergePreview'] = [$this->jsonBody()['blockedBy'] ?? null, $this->jsonBody()['absorbed']['name'] ?? ($this->jsonBody()['error']['message'] ?? null)];

            $data['statuses'] = $statuses;
            $observed[$who] = $data;
        }

        return $observed;
    }

    /**
     * Le slug de l'ingrédient que ce compte voit sous ce nom d'origine : le sien (copie) ou celui qui est à lui, sinon le commun.
     */
    private function ownedSlug(User $user, string $origin): string
    {
        $this->entityManager->clear();
        $copy = $this->dataset->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = ? AND kind = 'adoption'", [$user->getId(), $origin]);

        return is_string($copy) ? $copy : $origin;
    }

    /**
     * Les slugs d'un compte sans son identifiant : ce que le compte voit, comparable d'un compte à l'autre et d'un moment à l'autre.
     *
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    private function relative(User $user, array $slugs): array
    {
        // (un nom qui finit par un symbole donne un slug de copie qui finit par un tiret avant l'identifiant : « dentifrice adulte+ »)
        return array_map(static fn(string $slug): string => str_ends_with($slug, $user->getId()) ? rtrim(substr($slug, 0, -strlen($user->getId())), '-') : $slug, $slugs);
    }

    private function activeListOf(User $user): string
    {
        $this->entityManager->clear();

        return (string) $this->dataset->connection->fetchOne("SELECT id FROM shopping_list WHERE owner = ? AND status = 'ACTIVE' ORDER BY id LIMIT 1", [$user->getId()]);
    }

    /**
     * Les lignes de la liste dues à la recette : leur nombre et leurs quantités, sans les slugs.
     *
     * @return array{int, list<float>}
     */
    private function listRows(string $listId, string $recipeId): array
    {
        $this->entityManager->clear();
        $rows = $this->dataset->connection->fetchFirstColumn('SELECT quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient, quantity', [$listId]);

        return [count($rows), array_map('floatval', $rows)];
    }

    private function registerAndConfirm(string $email): string
    {
        $json = ['CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', '/api/v1/users', content: json_encode(['email' => $email, 'password' => 'un-mot-de-passe', 'defaultServing' => 3], JSON_THROW_ON_ERROR), server: $json);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $userId = (string) $this->dataset->connection->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        $this->assertSame(0, (int) $this->dataset->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$userId]), 'l\'inscription ne copie rien');
        $secret = bin2hex(random_bytes(16));
        static::getContainer()->get(EmailVerificationTokenRepository::class)->add(EmailVerificationToken::issue(UuidGenerator::new(), $userId, EmailVerificationToken::hash($secret), new DateTimeImmutable()));
        $this->client->request('POST', '/api/v1/users/email-verification/confirm', content: json_encode(['token' => $secret], JSON_THROW_ON_ERROR), server: $json);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        return $userId;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, mixed>
     */
    private function recipeBody(string $name, array $slugs): array
    {
        return [
            'name' => $name,
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => $slugs[0], 'unitSlug' => 'gramme', 'quantity' => 250],
                ['ingredientSlug' => $slugs[1], 'unitSlug' => 'gramme', 'quantity' => 100],
            ],
        ];
    }

    private function user(string $id): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneById($id);
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $content = $this->client->getResponse()->getContent();

        return $content === '' || $content === false ? [] : (json_decode($content, true) ?? []);
    }
}

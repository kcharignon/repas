<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * La dernière connexion d'un compte, de bout en bout, avec les vrais bus, la vraie base et les vrais jetons (ADR 0021,
 * amendement du 2026-10-20) : une connexion par mot de passe et un renouvellement de la session la font avancer (au plus toutes
 * les 10 minutes, et gratuitement quand elle n'avance pas), un refus ne l'écrit pas, l'administrateur la lit dans la liste des
 * comptes et nulle part ailleurs, la suppression du compte l'efface.
 */
class LastLoginFlowTest extends DatabaseTestCase
{
    private const string PASSWORD = 'test';

    private KernelBrowser $client;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->savedUser(new UserBuilder()->withEmail('admin@example.test')->isAdmin());
        $this->member = $this->savedUser(new UserBuilder()->withEmail('membre@example.test'));
    }

    public function testTheAdministratorSeesNullForAnAccountNeverSeenThenTheDateOfItsLogin(): void
    {
        // Given : le compte existe mais ne s'est pas connecté depuis le suivi
        $adminJwt = $this->login('admin@example.test');
        $this->assertNull($this->lastLoginsSeenByAdmin($adminJwt)['membre@example.test'], 'la clé est écrite, à null');

        // When
        $this->login('membre@example.test');

        // Then : la date de la connexion, en ATOM (UTC), à l'instant près
        $seen = $this->lastLoginsSeenByAdmin($adminJwt)['membre@example.test'];
        $this->assertNotNull($seen);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $seen);
        $this->assertEqualsWithDelta(time(), new DateTimeImmutable($seen)->getTimestamp(), 5);
    }

    public function testTheListKeepsTheKeyForEveryAccount(): void
    {
        // When
        $this->request('GET', '/api/v1/users', $this->login('admin@example.test'));

        // Then : le compte de l'administrateur (qui vient de se connecter) et l'autre ont la clé
        $members = $this->jsonBody()['member'];
        $this->assertCount(2, $members);
        foreach ($members as $member) {
            $this->assertArrayHasKey('lastLoginAt', $member);
        }
    }

    public function testRenewingTheSessionWithinTenMinutesDoesNotRewriteTheDate(): void
    {
        // Given : connecté il y a trois minutes
        $this->login('membre@example.test');
        $this->storeLastLogin($this->member, '-3 minutes');
        $stored = $this->storedLastLogin($this->member);

        // When
        $statements = $this->statementsDuring(fn() => $this->client->request('POST', '/api/auth/refresh'));

        // Then : la session est renouvelée, la date n'a pas bougé, et aucune écriture de la colonne n'a été tentée
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($stored, $this->storedLastLogin($this->member));
        $this->assertSame([], $this->loginWrites($statements));
    }

    public function testRenewingTheSessionAfterTenMinutesAdvancesTheDateWithOneStatement(): void
    {
        // Given : connecté il y a onze minutes
        $this->login('membre@example.test');
        $this->storeLastLogin($this->member, '-11 minutes');

        // When
        $statements = $this->statementsDuring(fn() => $this->client->request('POST', '/api/auth/refresh'));

        // Then : la date est celle du renouvellement, écrite par UNE instruction qui ne nomme que cette colonne
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEqualsWithDelta(time(), $this->storedLastLoginTimestamp($this->member), 5);
        $writes = $this->loginWrites($statements);
        $this->assertCount(1, $writes);
        $this->assertStringNotContainsString('password', $writes[0]);
        $this->assertStringNotContainsString('status', $writes[0]);
    }

    public function testALoginWithinTenMinutesIsFreeAndAnOlderOneCostsExactlyOneStatement(): void
    {
        // Given : deux comptes identiques, l'un connecté il y a trois minutes, l'autre il y a onze
        $recent = $this->savedUser(new UserBuilder()->withEmail('recent@example.test'));
        $old = $this->savedUser(new UserBuilder()->withEmail('ancien@example.test'));
        $this->storeLastLogin($recent, '-3 minutes');
        $this->storeLastLogin($old, '-11 minutes');
        $this->entityManager->clear();

        // When
        $recentStatements = $this->statementsDuring(fn() => $this->postLogin('recent@example.test', self::PASSWORD));
        $oldStatements = $this->statementsDuring(fn() => $this->postLogin('ancien@example.test', self::PASSWORD));

        // Then : zéro écriture pour le premier ; pour le second une seule, et rien d'autre en plus (aucune lecture de plus)
        $this->assertSame([], $this->loginWrites($recentStatements));
        $this->assertCount(1, $this->loginWrites($oldStatements));
        $this->assertCount(count($recentStatements) + 1, $oldStatements);
    }

    public function testARenewalWithinTenMinutesIsFreeAndAnOlderOneCostsExactlyOneStatement(): void
    {
        // Given : deux sessions ouvertes, l'une connectée il y a trois minutes, l'autre il y a onze
        $recent = $this->savedUser(new UserBuilder()->withEmail('recent@example.test'));
        $old = $this->savedUser(new UserBuilder()->withEmail('ancien@example.test'));
        $this->login('recent@example.test');
        $recentCookie = $this->client->getCookieJar()->get('repas_refresh', '/api/auth')->getValue();
        $this->client->getCookieJar()->clear();
        $this->login('ancien@example.test');
        $oldCookie = $this->client->getCookieJar()->get('repas_refresh', '/api/auth')->getValue();
        $this->storeLastLogin($recent, '-3 minutes');
        $this->storeLastLogin($old, '-11 minutes');

        // When
        $this->setCookie($recentCookie);
        $recentStatements = $this->statementsDuring(fn() => $this->client->request('POST', '/api/auth/refresh'));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->setCookie($oldCookie);
        $oldStatements = $this->statementsDuring(fn() => $this->client->request('POST', '/api/auth/refresh'));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Then
        $this->assertSame([], $this->loginWrites($recentStatements));
        $this->assertCount(1, $this->loginWrites($oldStatements));
        $this->assertCount(count($recentStatements) + 1, $oldStatements);
    }

    public function testRefusedLoginsWriteNothing(): void
    {
        // Given : un compte jamais vu, un compte en attente, un compte désactivé
        $pending = $this->savedUser(new UserBuilder()->withEmail('attente@example.test')->isPending());
        $disabled = $this->savedUser(new UserBuilder()->withEmail('desactive@example.test')->isDisabled());

        // When
        $statements = $this->statementsDuring(function (): void {
            $this->postLogin('membre@example.test', 'pas-le-mot-de-passe');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
            $this->postLogin('inconnu@example.test', self::PASSWORD);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
            $this->postLogin('attente@example.test', self::PASSWORD);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode());
            $this->postLogin('desactive@example.test', self::PASSWORD);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        });

        // Then : aucune date, aucune écriture
        foreach ([$this->member, $pending, $disabled] as $user) {
            $this->assertNull($this->storedLastLogin($user), $user->getEmail());
        }
        $this->assertSame([], $this->loginWrites($statements));
    }

    public function testRefusedRenewalsWriteNothing(): void
    {
        // Given : un compte jamais vu ; un cookie inconnu, puis un cookie déjà utilisé (rejeu)
        $this->login('membre@example.test');
        $this->storeLastLogin($this->member, null);
        $firstSecret = $this->secret();
        $this->client->request('POST', '/api/auth/refresh');
        $this->connection()->executeStatement("UPDATE refresh_token SET used_at = used_at - INTERVAL '1 minute' WHERE used_at IS NOT NULL");
        $this->storeLastLogin($this->member, null);

        // When
        $statements = $this->statementsDuring(function () use ($firstSecret): void {
            $this->setCookie('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
            $this->client->request('POST', '/api/auth/refresh');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
            $this->setCookie($firstSecret);
            $this->client->request('POST', '/api/auth/refresh');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
            $this->client->getCookieJar()->clear();
            $this->client->request('POST', '/api/auth/refresh');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        });

        // Then
        $this->assertNull($this->storedLastLogin($this->member));
        $this->assertSame([], $this->loginWrites($statements));
    }

    public function testADisabledAccountCannotRenewItsSessionNorMoveItsDate(): void
    {
        // Given : connecté, puis désactivé par l'administrateur
        $this->login('membre@example.test');
        $secret = $this->secret();
        $this->request('PUT', '/api/v1/users/' . $this->member->getId() . '/status', $this->login('admin@example.test'), ['status' => 'DISABLED']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->storeLastLogin($this->member, '-1 hour');
        $stored = $this->storedLastLogin($this->member);

        // When
        $this->setCookie($secret);
        $this->client->request('POST', '/api/auth/refresh');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame($stored, $this->storedLastLogin($this->member));
    }

    public function testAnOrdinaryRequestNeitherMovesNorShowsTheDate(): void
    {
        // Given : connecté il y a une heure, avec un jeton d'accès encore valable
        $jwt = $this->login('membre@example.test');
        $this->storeLastLogin($this->member, '-1 hour');
        $stored = $this->storedLastLogin($this->member);

        // When : requêtes ordinaires du jeton
        $statements = $this->statementsDuring(function () use ($jwt): void {
            $this->request('GET', '/api/v1/me', $jwt);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertArrayNotHasKey('lastLoginAt', $this->jsonBody());
            $this->request('GET', '/api/v1/users/' . $this->member->getId(), $jwt);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertArrayNotHasKey('lastLoginAt', $this->jsonBody());
        });

        // Then : rien n'est écrit, et seul l'administrateur voit la date (un membre n'a pas la liste)
        $this->assertSame($stored, $this->storedLastLogin($this->member));
        $this->assertSame([], $this->loginWrites($statements));
        $this->request('GET', '/api/v1/users', $jwt);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('lastLoginAt', $this->client->getResponse()->getContent());
    }

    public function testDeletingTheAccountDeletesItsLastLogin(): void
    {
        // Given : un compte qui s'est connecté
        $this->login('membre@example.test');
        $this->assertNotNull($this->storedLastLogin($this->member));

        // When : l'administrateur le supprime
        $this->request('DELETE', '/api/v1/users/' . $this->member->getId(), $this->login('admin@example.test'));

        // Then : la date était sur la ligne du compte, elle n'existe plus nulle part
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = :id', ['id' => $this->member->getId()]));
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE last_login_at IS NOT NULL'), 'seule la date de l\'administrateur reste');
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function login(string $email): string
    {
        $this->postLogin($email, self::PASSWORD);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $email);

        return $this->jsonBody()['token'];
    }

    private function postLogin(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, string $jwt, ?array $body = null): void
    {
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
    }

    /**
     * @return array<string, ?string> la dernière connexion de chaque compte, par adresse, telle que l'administrateur la lit
     *
     * @phpstan-impure chaque appel fait une requête et lit la base : deux appels de suite peuvent répondre autre chose
     */
    private function lastLoginsSeenByAdmin(string $adminJwt): array
    {
        $this->request('GET', '/api/v1/users', $adminJwt);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $lastLogins = [];
        foreach ($this->jsonBody()['member'] as $member) {
            $this->assertArrayHasKey('lastLoginAt', $member);
            $lastLogins[$member['email']] = $member['lastLoginAt'];
        }

        return $lastLogins;
    }

    /**
     * Remet la dernière connexion du compte à `$ago` (ex. `-3 minutes`), ou à rien (`null`), directement en base : un test ne
     * peut pas attendre dix minutes.
     */
    private function storeLastLogin(User $user, ?string $ago): void
    {
        $this->connection()->executeStatement(
            'UPDATE "user" SET last_login_at = :at WHERE id = :id',
            ['at' => $ago === null ? null : new DateTimeImmutable($ago)->format('Y-m-d H:i:s'), 'id' => $user->getId()],
        );
        $this->entityManager->clear();
    }

    private function storedLastLogin(User $user): ?string
    {
        $stored = $this->connection()->fetchOne('SELECT last_login_at FROM "user" WHERE id = :id', ['id' => $user->getId()]);

        return $stored === false ? null : $stored;
    }

    private function storedLastLoginTimestamp(User $user): int
    {
        return new DateTimeImmutable($this->storedLastLogin($user) . ' UTC')->getTimestamp();
    }

    /**
     * Les instructions SQL envoyées pendant `$request` (compteur de requêtes du noyau en cours : le client en redémarre un entre
     * deux requêtes).
     *
     * @return list<string>
     */
    private function statementsDuring(callable $request): array
    {
        static::getContainer()->get(QueryCounter::class)->reset();
        $request();

        return static::getContainer()->get(QueryCounter::class)->statements();
    }

    /**
     * Les écritures de la colonne `last_login_at`.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function loginWrites(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn(string $sql): bool => preg_match('/^\s*UPDATE\s+"?user"?\s+SET\b.*\blast_login_at\b/is', $sql) === 1,
        ));
    }

    private function secret(): string
    {
        return $this->client->getCookieJar()->get('repas_refresh', '/api/auth')->getValue();
    }

    private function setCookie(string $secret): void
    {
        $this->client->getCookieJar()->set(new Cookie('repas_refresh', $secret, null, '/api/auth', 'localhost'));
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

namespace Repas\Tests\Voice\InputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Model\OpaqueToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le parcours complet de la liaison de compte Alexa (VOX-202) avec les vrais bus, la vraie base, la vraie sécurité et les vrais
 * listeners : autoriser -> échanger -> rafraîchir -> résoudre -> délier -> le jeton ne résout plus. Alexa est jouée par ce test (aucun
 * appel à Amazon) : PKCE avec un vrai SHA-256, secret du client de `AlexaTestClient`.
 */
class VoiceLinkingApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testTheCompleteJourneyFromAuthorizationToUnlinking(): void
    {
        // Given : un utilisateur connecté qui a ouvert la page d'autorisation d'Alexa
        $user = $this->savedUser();
        $pkce = AlexaTestClient::pkce();

        // When : il touche « Autoriser » -> le front reçoit l'adresse où l'envoyer
        $redirectUrl = $this->authorize($user, $pkce['challenge'], 'state-xyz');

        // Then : l'adresse de retour autorisée, son state, un code opaque
        $this->assertSame(200, $this->statusCode());
        $parts = parse_url($redirectUrl);
        $this->assertSame(AlexaTestClient::REDIRECT_URI, $parts['scheme'] . '://' . $parts['host'] . $parts['path']);
        parse_str($parts['query'], $query);
        $this->assertSame('state-xyz', $query['state']);
        $this->assertTrue(OpaqueToken::isWellFormed($query['code']));

        // When : Alexa échange le code
        $first = $this->exchangeCode($query['code'], $pkce['verifier']);

        // Then : une paire de jetons de la bonne forme, pour une heure
        $this->assertSame(200, $this->statusCode());
        $this->assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token'], array_keys($first));
        $this->assertSame(['Bearer', 3600], [$first['token_type'], $first['expires_in']]);
        $this->assertTrue(OpaqueToken::isWellFormed($first['access_token']));
        $this->assertTrue(OpaqueToken::isWellFormed($first['refresh_token']));
        $this->assertNotSame($first['access_token'], $first['refresh_token']);

        // Then : le jeton d'accès résout l'utilisateur et sa liaison
        $resolved = $this->resolve($first['access_token']);
        $this->assertInstanceOf(ResolvedAccessGrantDTO::class, $resolved);
        $this->assertSame($user->getId(), $resolved->userId);

        // Then : l'utilisateur voit sa liaison, sans aucun jeton
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($user));
        $this->assertSame(200, $this->statusCode());
        $list = $this->jsonBody();
        $this->assertSame(1, $list['totalItems']);
        $this->assertSame(['id', 'provider', 'createdAt', 'lastUsedAt'], array_keys($list['member'][0]));
        $this->assertSame([$resolved->linkId, 'alexa', null], [$list['member'][0]['id'], $list['member'][0]['provider'], $list['member'][0]['lastUsedAt']]);
        $this->assertStringNotContainsString($first['access_token'], (string) $this->client->getResponse()->getContent());

        // When : une heure plus tard Alexa rafraîchit
        $second = $this->refresh($first['refresh_token']);

        // Then : une autre paire ; les deux jetons d'accès résolvent (l'ancien jusqu'à son expiration)
        $this->assertSame(200, $this->statusCode());
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertEquals($resolved, $this->resolve($second['access_token']));
        $this->assertEquals($resolved, $this->resolve($first['access_token']));

        // When : Alexa a reconnu la liaison (le webhook a posé l'identifiant d'utilisateur Alexa), puis l'utilisateur délie Alexa
        $this->entityManager->getConnection()->executeStatement('UPDATE voice_link SET alexa_user_id = ? WHERE id = ?', ['amzn1.ask.account.TEST', $resolved->linkId]);
        $this->call('DELETE', '/api/v1/voice/links/' . $resolved->linkId, $this->bearerFor($user));

        // Then : plus aucun jeton ne résout, plus aucun rafraîchissement ne passe, la liste est vide
        $this->assertSame(204, $this->statusCode());
        $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT alexa_user_id FROM voice_link WHERE id = ?', [$resolved->linkId]));
        $this->assertNotNull($this->entityManager->getConnection()->fetchOne('SELECT revoked_at FROM voice_link WHERE id = ?', [$resolved->linkId]));
        $this->assertNull($this->resolve($first['access_token']));
        $this->assertNull($this->resolve($second['access_token']));
        $this->refresh($second['refresh_token']);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($user));
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testAnAuthorizationCodeCanBeExchangedOnlyOnceAndItsReplayRevokesWhatItGave(): void
    {
        // Given : un code échangé une première fois
        [$user, $pkce, $code] = $this->authorizedCode();
        $first = $this->exchangeCode($code, $pkce['verifier']);
        $this->assertSame(200, $this->statusCode());
        $this->assertNotNull($this->resolve($first['access_token']));

        // When : le même code revient, avec les bonnes valeurs
        $this->exchangeCode($code, $pkce['verifier']);

        // Then : refus, et ce que le premier échange avait donné est révoqué
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->assertNull($this->resolve($first['access_token']));
        $this->refresh($first['refresh_token']);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($user));
        $this->assertSame(0, $this->jsonBody()['totalItems']);
    }

    public function testARefreshTokenThatAlreadyServedRevokesTheLink(): void
    {
        // Given : un jeton de rafraîchissement échangé une fois
        [$user, $pkce, $code] = $this->authorizedCode();
        $first = $this->exchangeCode($code, $pkce['verifier']);
        $second = $this->refresh($first['refresh_token']);
        $this->assertSame(200, $this->statusCode());
        $this->assertNotNull($this->resolve($second['access_token']));

        // When : le premier jeton de rafraîchissement revient (rejeu)
        $this->refresh($first['refresh_token']);

        // Then : refus, liaison révoquée, tous les jetons morts, y compris la paire légitime et sa suite
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->assertNull($this->resolve($first['access_token']));
        $this->assertNull($this->resolve($second['access_token']));
        $this->refresh($second['refresh_token']);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($user));
        $this->assertSame(0, $this->jsonBody()['totalItems']);
    }

    public function testAnExchangeThatDoesNotMatchTheAuthorizationIsRefusedAndBurnsTheCode(): void
    {
        $wrongs = [
            'verifier' => fn(array $pkce): array => ['redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => AlexaTestClient::pkce('z')['verifier']],
            'redirect uri' => fn(array $pkce): array => ['redirect_uri' => AlexaTestClient::OTHER_REDIRECT_URI, 'code_verifier' => $pkce['verifier']],
            'redirect uri with a trailing slash' => fn(array $pkce): array => ['redirect_uri' => AlexaTestClient::REDIRECT_URI . '/', 'code_verifier' => $pkce['verifier']],
            'malformed verifier' => fn(array $pkce): array => ['redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => 'short'],
        ];

        foreach ($wrongs as $name => $parameters) {
            // Given
            [, $pkce, $code] = $this->authorizedCode();

            // When
            $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code] + $parameters($pkce));

            // Then : refusé sans dire pourquoi ; le code est brûlé, même avec les bonnes valeurs ensuite
            $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()], $name);
            $this->exchangeCode($code, $pkce['verifier']);
            $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()], $name);
        }
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        // Given : un code émis il y a plus de dix minutes
        [, $pkce, $code] = $this->authorizedCode();
        $this->connection()->executeStatement("UPDATE voice_authorization_code SET expires_at = NOW() - INTERVAL '1 second'");

        // When
        $this->exchangeCode($code, $pkce['verifier']);

        // Then
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
    }

    public function testACodeIsRefusedForAnotherClientEvenWithTheRightSecret(): void
    {
        // Given : un code émis pour un autre client (par exemple si l'identifiant du client avait changé depuis)
        [, $pkce, $code] = $this->authorizedCode();
        $this->connection()->executeStatement("UPDATE voice_authorization_code SET client_id = 'old-client'");

        // When
        $this->exchangeCode($code, $pkce['verifier']);

        // Then
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
    }

    public function testAnAccountDisabledAfterTheAuthorizationGetsNoTokens(): void
    {
        // Given
        [$user, $pkce, $code] = $this->authorizedCode();
        $this->setStatus($user, UserStatus::DISABLED);

        // When
        $this->exchangeCode($code, $pkce['verifier']);

        // Then
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_access_grant'));
    }

    public function testAnAccountDisabledAfterTheLinkingResolvesNothingAndRefreshesNothing(): void
    {
        // Given : une liaison qui marche
        [$user, $pkce, $code] = $this->authorizedCode();
        $tokens = $this->exchangeCode($code, $pkce['verifier']);
        $this->assertNotNull($this->resolve($tokens['access_token']));

        // When : un administrateur désactive le compte
        $this->setStatus($user, UserStatus::DISABLED);

        // Then : le jeton d'accès ne résout plus, le jeton de rafraîchissement n'échange plus
        $this->assertNull($this->resolve($tokens['access_token']));
        $this->refresh($tokens['refresh_token']);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);

        // When : il le réactive -> la liaison est retrouvée (rien n'a été révoqué)
        $this->setStatus($user, UserStatus::ACTIVE);

        // Then
        $this->assertNotNull($this->resolve($tokens['access_token']));
    }

    public function testAPendingAccountResolvesNothing(): void
    {
        // Given
        [$user, $pkce, $code] = $this->authorizedCode();
        $tokens = $this->exchangeCode($code, $pkce['verifier']);

        // When
        $this->setStatus($user, UserStatus::PENDING);

        // Then
        $this->assertNull($this->resolve($tokens['access_token']));
    }

    public function testADeletedAccountTakesItsLinksAndTokensWithIt(): void
    {
        // Given
        [$user, $pkce, $code] = $this->authorizedCode();
        $tokens = $this->exchangeCode($code, $pkce['verifier']);
        $admin = $this->savedUser();
        $this->assertNotNull($this->resolve($tokens['access_token']));

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new DeleteUserCommand($user->getId(), $admin->getId()));

        // Then
        $this->assertNull($this->resolve($tokens['access_token']));
        foreach (['voice_link', 'voice_access_grant', 'voice_authorization_code'] as $table) {
            $this->assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }
        $this->refresh($tokens['refresh_token']);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$this->statusCode(), $this->jsonBody()]);
    }

    public function testAnotherUserCannotRemoveMyLinkAndItKeepsWorking(): void
    {
        // Given
        [$owner, $pkce, $code] = $this->authorizedCode();
        $tokens = $this->exchangeCode($code, $pkce['verifier']);
        $linkId = $this->resolve($tokens['access_token'])->linkId;
        $other = $this->savedUser();

        // When
        $this->call('DELETE', '/api/v1/voice/links/' . $linkId, $this->bearerFor($other));

        // Then
        $this->assertSame(403, $this->statusCode());
        $this->assertNotNull($this->resolve($tokens['access_token']));
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($other));
        $this->assertSame(0, $this->jsonBody()['totalItems']);
    }

    public function testLinkingAgainBeforeAlexaUsedTheLinkKeepsASingleLinkAndKillsTheOldTokens(): void
    {
        // Given : un premier lien
        [$user, $pkce, $code] = $this->authorizedCode();
        $first = $this->exchangeCode($code, $pkce['verifier']);

        // When : l'utilisateur relie Alexa de nouveau
        $pkce2 = AlexaTestClient::pkce('b');
        $code2 = $this->codeOf($this->authorize($user, $pkce2['challenge'], 'state-2'));
        $second = $this->exchangeCode($code2, $pkce2['verifier']);

        // Then : une seule liaison, les anciens jetons sont morts, les nouveaux servent
        $this->call('GET', '/api/v1/voice/links', $this->bearerFor($user));
        $this->assertSame(1, $this->jsonBody()['totalItems']);
        $this->assertNull($this->resolve($first['access_token']));
        $this->assertNotNull($this->resolve($second['access_token']));
        $this->refresh($first['refresh_token']);
        $this->assertSame(400, $this->statusCode());
        $this->assertNotNull($this->resolve($second['access_token']), 'un refus de jeton inconnu ne révoque pas la liaison');
    }

    public function testTwoUsersHaveTheirOwnLinksAndTokens(): void
    {
        // Given
        [$alice, $pkceA, $codeA] = $this->authorizedCode();
        [$bob, $pkceB, $codeB] = $this->authorizedCode();

        // When
        $tokensA = $this->exchangeCode($codeA, $pkceA['verifier']);
        $tokensB = $this->exchangeCode($codeB, $pkceB['verifier']);

        // Then
        $this->assertSame($alice->getId(), $this->resolve($tokensA['access_token'])->userId);
        $this->assertSame($bob->getId(), $this->resolve($tokensB['access_token'])->userId);
        $this->assertNotSame($this->resolve($tokensA['access_token'])->linkId, $this->resolve($tokensB['access_token'])->linkId);
    }

    public function testAnAlexaTokenIsNeverAcceptedByTheJwtFirewallAndAJwtResolvesNothing(): void
    {
        // Given
        [$user, $pkce, $code] = $this->authorizedCode();
        $tokens = $this->exchangeCode($code, $pkce['verifier']);
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        // When : le jeton d'accès d'Alexa sur des routes de l'API de l'application, sans ou avec le chemin vocal
        foreach (['/api/v1/me', '/api/v1/voice/links', '/api/v1/shopping-lists'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token']]);

            // Then
            $this->assertSame(401, $this->statusCode(), $path);
        }
        // Then : et un JWT n'est pas un jeton Alexa
        $this->assertNull($this->resolve($jwt));
        // Then : le jeton de rafraîchissement n'ouvre rien non plus
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['refresh_token']]);
        $this->assertSame(401, $this->statusCode());
        $this->assertNull($this->resolve($tokens['refresh_token']));
    }

    public function testNeitherACodeNorATokenNorTheSecretIsStoredInClear(): void
    {
        // Given : un parcours complet, rafraîchissement compris
        [, $pkce, $code] = $this->authorizedCode();
        $first = $this->exchangeCode($code, $pkce['verifier']);
        $second = $this->refresh($first['refresh_token']);
        $this->assertSame(200, $this->statusCode());

        // When : on lit TOUTES les tables vocales
        $dump = '';
        foreach (['voice_link', 'voice_authorization_code', 'voice_access_grant'] as $table) {
            $dump .= json_encode($this->connection()->fetchAllAssociative("SELECT * FROM $table"), JSON_THROW_ON_ERROR);
        }

        // Then : aucun secret en clair ; seuls les hachés y sont
        foreach ([$code, $first['access_token'], $first['refresh_token'], $second['access_token'], $second['refresh_token'], $pkce['verifier'], AlexaTestClient::SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        foreach ([$code, $first['access_token'], $first['refresh_token'], $second['access_token'], $second['refresh_token']] as $secret) {
            $this->assertStringContainsString(OpaqueToken::hash($secret), $dump);
        }
        $this->assertSame(
            [OpaqueToken::hash($code)],
            $this->connection()->fetchFirstColumn('SELECT code_hash FROM voice_authorization_code'),
        );
        $this->assertSame(
            [64],
            array_unique(array_map('strlen', $this->connection()->fetchFirstColumn('SELECT access_hash FROM voice_access_grant UNION ALL SELECT refresh_hash FROM voice_access_grant'))),
        );
    }

    public function testTheTokenEndpointAnswersOnlyToTheRealClientAndLeavesTheDatabaseUntouched(): void
    {
        // Given : un code valable
        [, $pkce, $code] = $this->authorizedCode();

        // When : un faux client, avec les bons code et vérificateur
        $this->client->request('POST', '/api/voice/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => $pkce['verifier']], server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('repas-alexa:wrong')]);

        // Then : refus, rien n'est consommé, le vrai client peut encore échanger
        $this->assertSame([401, ['error' => 'invalid_client']], [$this->statusCode(), $this->jsonBody()]);
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_authorization_code WHERE used_at IS NOT NULL'));
        $this->exchangeCode($code, $pkce['verifier']);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAuthorizationIsRefusedForAnUnknownClientOrRedirectUriAndStoresNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $pkce = AlexaTestClient::pkce();

        foreach ([
            ['clientId' => 'another-client'],
            ['redirectUri' => 'https://evil.example.test/callback'],
            ['redirectUri' => AlexaTestClient::REDIRECT_URI . '/..'],
            ['codeChallengeMethod' => 'plain'],
            ['codeChallenge' => 'short'],
        ] as $override) {
            // When
            $this->call('POST', '/api/v1/voice/alexa/authorize', $this->bearerFor($user), json_encode($override + [
                'clientId' => AlexaTestClient::CLIENT_ID,
                'redirectUri' => AlexaTestClient::REDIRECT_URI,
                'state' => 'xyz',
                'codeChallenge' => $pkce['challenge'],
                'codeChallengeMethod' => 'S256',
            ], JSON_THROW_ON_ERROR));

            // Then
            $this->assertSame(422, $this->statusCode(), json_encode($override));
            $this->assertSame(['error' => ['message' => 'VOICE_AUTHORIZATION_REFUSED']], $this->jsonBody());
        }
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_authorization_code'));
    }

    public function testTheTokenEndpointIsRateLimitedPerIpAddress(): void
    {
        // Given : 30 appels par heure et par adresse (config/packages/rate_limiter.yaml), le noyau gardé d'une requête à l'autre
        $this->client->disableReboot();
        $request = fn(string $ip) => $this->client->request('POST', '/api/voice/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x'], server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('repas-alexa:wrong'), 'REMOTE_ADDR' => $ip]);

        // When : trente appels passent le plafond (refusés ensuite par le client inconnu), le trente et unième est arrêté
        for ($i = 1; $i <= 30; $i++) {
            $request('203.0.113.50');
            $this->assertSame(401, $this->statusCode(), "appel $i");
        }
        $request('203.0.113.50');

        // Then
        $this->assertSame(429, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));

        // Then : une autre adresse n'est pas touchée
        $request('203.0.113.51');
        $this->assertSame(401, $this->statusCode());
    }

    public function testTheAuthorizationIsRateLimitedPerUser(): void
    {
        // Given : 20 émissions par heure et par utilisateur
        $this->client->disableReboot();
        $user = $this->savedUser();
        $other = $this->savedUser();
        $pkce = AlexaTestClient::pkce();

        // When
        for ($i = 1; $i <= 20; $i++) {
            $this->authorize($user, $pkce['challenge'], "state-$i");
            $this->assertSame(200, $this->statusCode(), "émission $i");
        }
        $this->authorize($user, $pkce['challenge'], 'state-21');

        // Then : la vingt et unième est arrêtée, un autre utilisateur n'est pas touché
        $this->assertSame(429, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->authorize($other, $pkce['challenge'], 'state-1');
        $this->assertSame(200, $this->statusCode());
    }

    /**
     * @return array{User, array{verifier: string, challenge: string}, string}
     */
    private function authorizedCode(): array
    {
        $user = $this->savedUser();
        $pkce = AlexaTestClient::pkce();

        return [$user, $pkce, $this->codeOf($this->authorize($user, $pkce['challenge'], 'state'))];
    }

    private function codeOf(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);

        return $query['code'];
    }

    private function authorize(User $user, string $challenge, string $state): string
    {
        $this->call('POST', '/api/v1/voice/alexa/authorize', $this->bearerFor($user), json_encode([
            'clientId' => AlexaTestClient::CLIENT_ID,
            'redirectUri' => AlexaTestClient::REDIRECT_URI,
            'state' => $state,
            'codeChallenge' => $challenge,
            'codeChallengeMethod' => 'S256',
        ], JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode() === 200 ? $this->jsonBody()['redirectUrl'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code, string $verifier): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => $verifier]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Alexa : `POST` d'un formulaire, client authentifié par `Authorization: Basic`.
     *
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(array $form): array
    {
        $this->client->request('POST', '/api/voice/oauth/token', $form, server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(AlexaTestClient::CLIENT_ID . ':' . AlexaTestClient::SECRET)]);

        return $this->jsonBody();
    }

    private function resolve(string $accessToken): ?ResolvedAccessGrantDTO
    {
        return static::getContainer()->get(QueryBusInterface::class)->ask(new ResolveAccessGrantQuery($accessToken));
    }

    private function call(string $method, string $uri, array $server, ?string $content = null): void
    {
        $this->client->request($method, $uri, content: $content, server: $server + ['CONTENT_TYPE' => 'application/json']);
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function setStatus(User $user, UserStatus $status): void
    {
        $this->connection()->executeStatement('UPDATE "user" SET status = :status WHERE id = :id', ['status' => $status->value, 'id' => $user->getId()]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}

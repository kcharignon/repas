<?php

namespace Repas\Tests\Voice\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Voice\Domain\Interface\VoiceFeature;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\AlexaOAuthClientInMemory;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Voice\Application\Command\ExchangeAuthorizationCode\ExchangeAuthorizationCodeCommand;
use Repas\Voice\Application\Command\IssuedTokens;
use Repas\Voice\Application\Command\RefreshAccessGrant\RefreshAccessGrantCommand;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Repas\Voice\Infrastructure\Http\Api\ExchangeOAuthToken\ExchangeOAuthTokenApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le point d'accès aux jetons de la liaison de compte (VOX-202), avec un bus de commandes factice : statuts, clés JSON, en-têtes. Le
 * client est authentifié par la vraie configuration de l'environnement de test (`AlexaTestClient`).
 */
class ExchangeOAuthTokenApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = '/api/voice/oauth/token';

    public function testTheCodeIsExchangedForTokensWithTheCredentialsInTheBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(ExchangeAuthorizationCodeCommand::class, new IssuedTokens('the-access-token', 'the-refresh-token', 3600));

        // When
        $this->post($this->codeForm() + $this->bodyCredentials());

        // Then : les quatre clés d'OAuth, `Bearer`, une heure ; jamais en cache
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'the-refresh-token'], $this->jsonBody());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertEquals([new ExchangeAuthorizationCodeCommand(AlexaTestClient::CLIENT_ID, 'the-code', AlexaTestClient::REDIRECT_URI, 'the-verifier')], $commandBus->dispatched());
    }

    public function testTheCodeIsExchangedWithBasicAuthorizationToo(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(ExchangeAuthorizationCodeCommand::class, new IssuedTokens('a', 'r', 3600));

        // When
        $this->post($this->codeForm(), $this->basicHeader());

        // Then : le secret ne traverse pas le bus, seul l'identifiant du client
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ExchangeAuthorizationCodeCommand(AlexaTestClient::CLIENT_ID, 'the-code', AlexaTestClient::REDIRECT_URI, 'the-verifier')], $commandBus->dispatched());
        $this->assertStringNotContainsString(AlexaTestClient::SECRET, serialize($commandBus->dispatched()));
    }

    public function testTheRefreshTokenIsExchangedForNewTokens(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(RefreshAccessGrantCommand::class, new IssuedTokens('new-access', 'new-refresh', 3600));

        // When
        $this->post(['grant_type' => 'refresh_token', 'refresh_token' => 'the-refresh-token'], $this->basicHeader());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['access_token' => 'new-access', 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'new-refresh'], $this->jsonBody());
        $this->assertEquals([new RefreshAccessGrantCommand('the-refresh-token')], $commandBus->dispatched());
    }

    public function testAHandlerRefusalAnswersInvalidGrantWithoutSayingWhy(): void
    {
        // Given : le cas d'usage refuse (code inconnu, rejoué, expiré, vérificateur faux, compte inactif… : tout est `null`)
        $this->mockCommandBus()->willReturn(ExchangeAuthorizationCodeCommand::class, null);

        // When
        $this->post($this->codeForm() + $this->bodyCredentials());

        // Then
        $this->assertOAuthError(400, 'invalid_grant');
        $this->assertStringNotContainsString('the-code', (string) $this->client->getResponse()->getContent());
    }

    public function testARefreshRefusalAnswersInvalidGrantToo(): void
    {
        $this->mockCommandBus()->willReturn(RefreshAccessGrantCommand::class, null);

        $this->post(['grant_type' => 'refresh_token', 'refresh_token' => 'x'], $this->basicHeader());

        $this->assertOAuthError(400, 'invalid_grant');
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $server
     */
    #[DataProvider('unauthenticatedRequests')]
    public function testAnUnknownClientAnswersInvalidClientAndNothingIsDispatched(array $form, array $server): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($form, $server);

        // Then : 401, le défi `Basic`, aucune indication de ce qui est faux
        $this->assertOAuthError(401, 'invalid_client');
        $this->assertSame('Basic realm="Repas"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function unauthenticatedRequests(): iterable
    {
        $code = ['grant_type' => 'authorization_code', 'code' => 'c', 'redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => 'v'];
        $basic = static fn(string $credentials): array => ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($credentials)];

        yield 'no credentials' => [$code, []];
        yield 'wrong secret in the body' => [$code + ['client_id' => AlexaTestClient::CLIENT_ID, 'client_secret' => 'wrong'], []];
        yield 'wrong client id in the body' => [$code + ['client_id' => 'another', 'client_secret' => AlexaTestClient::SECRET], []];
        yield 'empty secret in the body' => [$code + ['client_id' => AlexaTestClient::CLIENT_ID, 'client_secret' => ''], []];
        yield 'client id without secret' => [$code + ['client_id' => AlexaTestClient::CLIENT_ID], []];
        yield 'wrong secret with basic' => [$code, $basic(AlexaTestClient::CLIENT_ID . ':wrong')];
        yield 'wrong client id with basic' => [$code, $basic('another:' . AlexaTestClient::SECRET)];
        yield 'the secret hash as secret' => [$code, $basic(AlexaTestClient::CLIENT_ID . ':' . AlexaTestClient::SECRET_HASH)];
        yield 'basic and body secret at once' => [$code + ['client_secret' => AlexaTestClient::SECRET], $basic(AlexaTestClient::CLIENT_ID . ':' . AlexaTestClient::SECRET)];
        yield 'a bearer token instead' => [$code + ['client_id' => AlexaTestClient::CLIENT_ID, 'client_secret' => AlexaTestClient::SECRET], ['HTTP_AUTHORIZATION' => 'Bearer abc']];
        yield 'invalid base64' => [$code, ['HTTP_AUTHORIZATION' => 'Basic !!!']];
        yield 'a refresh token request without credentials' => [['grant_type' => 'refresh_token', 'refresh_token' => 'r'], []];
    }

    public function testCredentialsInTheQueryStringAreNeverRead(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : un secret dans l'adresse (qui finirait dans les journaux)
        $this->client->request('POST', self::URL . '?client_id=' . AlexaTestClient::CLIENT_ID . '&client_secret=' . AlexaTestClient::SECRET, $this->codeForm());

        // Then
        $this->assertOAuthError(401, 'invalid_client');
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testTheClientIsAuthenticatedBeforeAnythingElseIsSaidAboutTheRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : un type inconnu, sans client
        $this->post(['grant_type' => 'password', 'username' => 'a', 'password' => 'b']);

        // Then : 401, pas 400 : un inconnu n'apprend rien sur les types acceptés
        $this->assertOAuthError(401, 'invalid_client');
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $form
     */
    #[DataProvider('badRequests')]
    public function testAnAuthenticatedClientWithABadRequestAnswers400(array $form, string $error): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($form, $this->basicHeader());

        // Then
        $this->assertOAuthError(400, $error);
        $this->assertSame([], $commandBus->dispatched());
        $this->assertNull($this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badRequests(): iterable
    {
        $code = ['grant_type' => 'authorization_code', 'code' => 'c', 'redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => 'v'];

        yield 'no grant type' => [[], 'invalid_request'];
        yield 'empty grant type' => [['grant_type' => ''], 'invalid_request'];
        yield 'grant type as an array' => [['grant_type' => ['authorization_code']], 'invalid_request'];
        yield 'password grant' => [['grant_type' => 'password', 'username' => 'a', 'password' => 'b'], 'unsupported_grant_type'];
        yield 'client credentials grant' => [['grant_type' => 'client_credentials'], 'unsupported_grant_type'];
        yield 'implicit' => [['grant_type' => 'implicit'], 'unsupported_grant_type'];
        yield 'grant type in another case' => [['grant_type' => 'Authorization_Code'] + $code, 'unsupported_grant_type'];
        yield 'code missing' => [array_diff_key($code, ['code' => 1]), 'invalid_request'];
        yield 'redirect uri missing' => [array_diff_key($code, ['redirect_uri' => 1]), 'invalid_request'];
        yield 'verifier missing (PKCE is mandatory)' => [array_diff_key($code, ['code_verifier' => 1]), 'invalid_request'];
        yield 'code empty' => [['code' => ''] + $code, 'invalid_request'];
        yield 'code as an array' => [['code' => ['c']] + $code, 'invalid_request'];
        yield 'refresh token missing' => [['grant_type' => 'refresh_token'], 'invalid_request'];
        yield 'refresh token as an array' => [['grant_type' => 'refresh_token', 'refresh_token' => ['r']], 'invalid_request'];
    }

    public function testAJsonBodyIsNotAFormAndIsAnInvalidRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : un client qui poste du JSON au lieu d'un formulaire
        $this->client->request('POST', self::URL, content: json_encode($this->codeForm(), JSON_THROW_ON_ERROR), server: $this->basicHeader() + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertOAuthError(400, 'invalid_request');
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testBasicCredentialsEncodedAsAFormAreAcceptedWhenTheRawOnesFail(): void
    {
        // Given : un client dont le secret contient des caractères que la RFC 6749 2.3.1 fait encoder (`+`, `=`), et qui les encode
        $client = new class implements AlexaOAuthClient {
            public function isClientId(string $clientId): bool
            {
                return $clientId === 'repas-alexa';
            }

            public function isRedirectUriAllowed(string $redirectUri): bool
            {
                return false;
            }

            public function authenticate(string $clientId, string $clientSecret): bool
            {
                return $clientId === 'repas-alexa' && $clientSecret === 'a+b=c';
            }
        };
        $commandBus = new MockCommandBus();
        $commandBus->willReturn(RefreshAccessGrantCommand::class, new IssuedTokens('a', 'r', 3600));
        $controller = new ExchangeOAuthTokenApiController($commandBus, $client);

        // When : encodé (`a%2Bb%3Dc`) puis brut (`a+b=c`)
        foreach (['repas-alexa:a%2Bb%3Dc', 'repas-alexa:a+b=c'] as $credentials) {
            $request = Request::create(self::URL, 'POST', ['grant_type' => 'refresh_token', 'refresh_token' => 'r']);
            $request->headers->set('Authorization', 'Basic ' . base64_encode($credentials));

            // Then
            $this->assertSame(200, $controller($request)->getStatusCode(), $credentials);
        }
        $request = Request::create(self::URL, 'POST', ['grant_type' => 'refresh_token', 'refresh_token' => 'r']);
        $request->headers->set('Authorization', 'Basic ' . base64_encode('repas-alexa:a%2Bb'));
        $this->assertSame(401, $controller($request)->getStatusCode());
    }

    public function testAnswers404VoiceDisabledWhenTheSwitchIsOffBeforeAnyCheck(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));
        $commandBus = $this->mockCommandBus();

        // When : même avec les bons identifiants
        $this->post($this->codeForm(), $this->basicHeader());

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->mockCommandBus();

        $this->client->request('GET', self::URL . '?grant_type=refresh_token&refresh_token=r', server: $this->basicHeader());

        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testTheRouteIsPublicAndAJwtOfTheAppIsNotNeeded(): void
    {
        // Given : aucun JWT (Amazon n'en a pas), les identifiants du client suffisent
        $commandBus = $this->mockCommandBus()->willReturn(RefreshAccessGrantCommand::class, new IssuedTokens('a', 'r', 3600));

        // When
        $this->post(['grant_type' => 'refresh_token', 'refresh_token' => 'r'], $this->basicHeader());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $server
     */
    private function post(array $form, array $server = []): void
    {
        $this->client->request('POST', self::URL, $form, server: $server);
    }

    /**
     * @return array<string, string>
     */
    private function bodyCredentials(): array
    {
        return ['client_id' => AlexaTestClient::CLIENT_ID, 'client_secret' => AlexaTestClient::SECRET];
    }

    /**
     * @return array<string, string>
     */
    private function basicHeader(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(AlexaTestClient::CLIENT_ID . ':' . AlexaTestClient::SECRET)];
    }

    /**
     * @return array<string, string>
     */
    private function codeForm(): array
    {
        return ['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => AlexaTestClient::REDIRECT_URI, 'code_verifier' => 'the-verifier'];
    }

    private function assertOAuthError(int $status, string $error): void
    {
        $response = $this->client->getResponse();
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame(['error' => $error], $this->jsonBody());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }
}

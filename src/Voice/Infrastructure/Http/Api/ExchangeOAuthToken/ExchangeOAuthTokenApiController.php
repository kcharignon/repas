<?php

namespace Repas\Voice\Infrastructure\Http\Api\ExchangeOAuthToken;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Repas\Voice\Application\Command\ExchangeAuthorizationCode\ExchangeAuthorizationCodeCommand;
use Repas\Voice\Application\Command\IssuedTokens;
use Repas\Voice\Application\Command\RefreshAccessGrant\RefreshAccessGrantCommand;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Repas\Voice\Infrastructure\Http\VoiceRateLimit;
use Repas\Voice\Infrastructure\Http\VoiceRateLimited;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresVoice]
#[VoiceRateLimited(VoiceRateLimit::TOKEN)]
final class ExchangeOAuthTokenApiController extends AbstractController
{
    public const string GRANT_AUTHORIZATION_CODE = 'authorization_code';
    public const string GRANT_REFRESH_TOKEN = 'refresh_token';

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly AlexaOAuthClient $oauthClient,
    ) {
    }

    // Route PUBLIQUE (firewall `voice` sans sécurité, config/packages/security.yaml) : Amazon n'a pas de JWT. Le client s'authentifie par
    // ses identifiants OAuth, et le plafond de débit (30 par heure et par adresse IP) est posé par `VoiceRateLimitListener`.
    #[Route(path: '/api/voice/oauth/token', name: 'api_voice_oauth_token', methods: ['POST'])]
    #[OA\Post(
        summary: "Point d'accès aux jetons de la liaison de compte (OAuth 2.0) : échange le code d'autorisation, ou le jeton de rafraîchissement, contre des jetons (route publique, appelée par Amazon)",
        description: "Corps `application/x-www-form-urlencoded` (RFC 6749). Le client est authentifié par `client_id` et `client_secret` dans le corps, ou par `Authorization: Basic` ; le secret est comparé à l'empreinte `ALEXA_OAUTH_CLIENT_SECRET_HASH` (vide : tout client est refusé). `grant_type=authorization_code` demande `code`, `redirect_uri` (la même qu'à l'autorisation) et `code_verifier` (PKCE S256) ; `grant_type=refresh_token` demande `refresh_token`. Les jetons sont opaques ; le jeton d'accès vaut 3600 s, le jeton de rafraîchissement 180 jours et ne sert qu'une fois : chaque échange en rend un nouveau (rotation), et présenter un jeton de rafraîchissement déjà échangé, ou un code déjà échangé, révoque la liaison. Les erreurs suivent la RFC 6749 5.2 (`{\"error\": \"…\"}`), sans dire pourquoi un échange est refusé : `invalid_grant` pour un code ou un jeton refusé quelle qu'en soit la raison (Alexa déconnecte alors le compte), `invalid_client` pour un client inconnu ou un secret faux, `invalid_request` pour un paramètre manquant, `unsupported_grant_type` pour un autre type. 30 appels par heure et par adresse IP. Jamais de mise en cache.",
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['grant_type'],
                    properties: [
                        new OA\Property(property: 'grant_type', type: 'string', enum: ['authorization_code', 'refresh_token']),
                        new OA\Property(property: 'code', type: 'string', description: "Avec `authorization_code` : le code reçu à l'autorisation"),
                        new OA\Property(property: 'redirect_uri', type: 'string', description: "Avec `authorization_code` : l'adresse de retour de l'autorisation, la même"),
                        new OA\Property(property: 'code_verifier', type: 'string', description: "Avec `authorization_code` : le vérificateur PKCE (43 à 128 caractères) dont le SHA-256 est le `code_challenge` de l'autorisation"),
                        new OA\Property(property: 'refresh_token', type: 'string', description: 'Avec `refresh_token` : le jeton de rafraîchissement reçu en dernier'),
                        new OA\Property(property: 'client_id', type: 'string', description: 'Authentification dans le corps (ou `Authorization: Basic`)'),
                        new OA\Property(property: 'client_secret', type: 'string', description: 'Authentification dans le corps (ou `Authorization: Basic`)'),
                    ],
                ),
            ),
        ),
    )]
    #[OA\Tag(name: 'Voice')]
    #[OA\Response(
        response: 200,
        description: 'Une paire de jetons. `Cache-Control: no-store`',
        headers: [new OA\Header(header: 'Cache-Control', description: '`no-store`', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'access_token', type: 'string', example: '3Qa5mVq1xT0Zb8nWc2YpLkR7sHdJ9eUfGhAoIiNtBvM'),
            new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
            new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
            new OA\Property(property: 'refresh_token', type: 'string', example: 'Zt4Lw9RkEoPq2YhXn6VbGcJdUaSf8MiH1TgNyQr0eKs'),
        ]),
    )]
    #[OA\Response(response: 400, description: 'Erreur OAuth `{"error": "invalid_request" | "invalid_grant" | "unsupported_grant_type"}`', content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string', example: 'invalid_grant')]))]
    #[OA\Response(
        response: 401,
        description: 'Erreur OAuth `{"error": "invalid_client"}` : client inconnu, secret faux ou absent',
        headers: [new OA\Header(header: 'WWW-Authenticate', description: '`Basic realm="Repas"`', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string', example: 'invalid_client')]),
    )]
    #[OA\Response(response: 404, description: 'Commande vocale coupée (message `VOICE_DISABLED`)')]
    #[OA\Response(response: 429, ref: '#/components/responses/VoiceTokenRateLimited')]
    public function __invoke(Request $request): Response
    {
        $clientId = $this->authenticatedClientId($request);
        if ($clientId === null) {
            return $this->error('invalid_client', Response::HTTP_UNAUTHORIZED);
        }

        // Seul le corps du formulaire compte (jamais l'adresse) ; un paramètre répété en tableau (`code[]=`) n'est pas une chaîne
        $form = $request->request->all();
        $field = static fn(string $name): ?string => is_string($form[$name] ?? null) && $form[$name] !== '' ? $form[$name] : null;

        $grantType = $field('grant_type');
        if ($grantType === null) {
            return $this->error('invalid_request', Response::HTTP_BAD_REQUEST);
        }

        $command = match ($grantType) {
            self::GRANT_AUTHORIZATION_CODE => $this->exchangeCommand($clientId, $field),
            self::GRANT_REFRESH_TOKEN => $this->refreshCommand($field),
            default => null,
        };
        if ($command === null) {
            return $this->error(
                in_array($grantType, [self::GRANT_AUTHORIZATION_CODE, self::GRANT_REFRESH_TOKEN], true) ? 'invalid_request' : 'unsupported_grant_type',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $tokens = $this->commandBus->dispatch($command);
        if (!$tokens instanceof IssuedTokens) {
            return $this->error('invalid_grant', Response::HTTP_BAD_REQUEST);
        }

        return $this->noStore(new JsonResponse([
            'access_token' => $tokens->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $tokens->expiresIn,
            'refresh_token' => $tokens->refreshToken,
        ]));
    }

    /**
     * L'identifiant du client si ses identifiants sont les nôtres, sinon `null`. Les valeurs brutes d'abord, puis (en-tête `Basic`
     * seulement) les mêmes décodées comme un formulaire : la RFC 6749 2.3.1 l'exige, certains clients ne le font pas.
     */
    private function authenticatedClientId(Request $request): ?string
    {
        $credentials = OAuthClientCredentials::fromRequest($request);
        if ($credentials === null) {
            return null;
        }

        if ($this->oauthClient->authenticate($credentials->clientId, $credentials->clientSecret)) {
            return $credentials->clientId;
        }

        if ($credentials->viaHeader) {
            $decoded = $credentials->urlDecoded();
            if ($this->oauthClient->authenticate($decoded->clientId, $decoded->clientSecret)) {
                return $decoded->clientId;
            }
        }

        return null;
    }

    /**
     * @param callable(string): ?string $field
     */
    private function exchangeCommand(string $clientId, callable $field): ?ExchangeAuthorizationCodeCommand
    {
        $code = $field('code');
        $redirectUri = $field('redirect_uri');
        $codeVerifier = $field('code_verifier');

        return $code === null || $redirectUri === null || $codeVerifier === null
            ? null
            : new ExchangeAuthorizationCodeCommand($clientId, $code, $redirectUri, $codeVerifier);
    }

    /**
     * @param callable(string): ?string $field
     */
    private function refreshCommand(callable $field): ?RefreshAccessGrantCommand
    {
        $refreshToken = $field('refresh_token');

        return $refreshToken === null ? null : new RefreshAccessGrantCommand($refreshToken);
    }

    private function error(string $error, int $status): JsonResponse
    {
        $response = $this->noStore(new JsonResponse(['error' => $error], $status));
        if ($status === Response::HTTP_UNAUTHORIZED) {
            $response->headers->set('WWW-Authenticate', 'Basic realm="Repas"');
        }

        return $response;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}

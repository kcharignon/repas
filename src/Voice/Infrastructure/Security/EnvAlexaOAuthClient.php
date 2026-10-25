<?php

namespace Repas\Voice\Infrastructure\Security;


use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le client OAuth de la liaison de compte, d'après l'environnement du déploiement (ADR 0029 décision 6) :
 *
 * - `ALEXA_OAUTH_CLIENT_ID` : l'identifiant public du client (le même que dans la console Amazon) ;
 * - `ALEXA_OAUTH_CLIENT_SECRET_HASH` : l'EMPREINTE du secret, SHA-256 en hexadécimal (64 caractères) d'un secret aléatoire d'au moins
 *   32 octets, jamais le secret ; comparée à temps constant (`hash_equals`). Vide ou d'une autre forme : tout client est refusé ;
 * - `ALEXA_REDIRECT_URIS` : les adresses de retour autorisées, séparées par des virgules, comparées EXACTEMENT (jamais par préfixe).
 *   Seule une adresse `https://` sans fragment est retenue ; une autre est ignorée (échec fermé). Aucune : aucune liaison possible.
 */
final readonly class EnvAlexaOAuthClient implements AlexaOAuthClient
{
    private const int MAX_CLIENT_ID_LENGTH = 100;
    private const int MAX_REDIRECT_URI_LENGTH = 2048;

    private string $clientId;
    private string $secretHash;
    /** @var list<string> */
    private array $redirectUris;

    public function __construct(
        #[Autowire('%env(string:default::ALEXA_OAUTH_CLIENT_ID)%')]
        string $clientId,
        #[Autowire('%env(string:default::ALEXA_OAUTH_CLIENT_SECRET_HASH)%')]
        string $secretHash,
        #[Autowire('%env(string:default::ALEXA_REDIRECT_URIS)%')]
        string $redirectUris,
    ) {
        $this->clientId = trim($clientId);
        $secretHash = strtolower(trim($secretHash));
        $this->secretHash = preg_match('/\A[0-9a-f]{64}\z/', $secretHash) === 1 ? $secretHash : '';
        $this->redirectUris = array_values(array_filter(
            array_map('trim', explode(',', $redirectUris)),
            static fn(string $uri): bool => str_starts_with($uri, 'https://') && !str_contains($uri, '#') && strlen($uri) <= self::MAX_REDIRECT_URI_LENGTH,
        ));
    }

    public function isClientId(string $clientId): bool
    {
        return $this->clientId !== '' && strlen($clientId) <= self::MAX_CLIENT_ID_LENGTH && hash_equals($this->clientId, $clientId);
    }

    public function isRedirectUriAllowed(string $redirectUri): bool
    {
        return in_array($redirectUri, $this->redirectUris, true);
    }

    public function authenticate(string $clientId, string $clientSecret): bool
    {
        // Les deux comparaisons sont toujours faites : rien n'indique laquelle a échoué, ni par le temps de réponse
        $idMatches = $this->isClientId($clientId);
        $secretMatches = hash_equals($this->secretHash, hash('sha256', $clientSecret));

        // Sans empreinte configurée (chaîne vide), `hash_equals` refuse déjà (longueurs différentes) ; le dire explicitement
        return $idMatches && $secretMatches && $this->secretHash !== '';
    }
}

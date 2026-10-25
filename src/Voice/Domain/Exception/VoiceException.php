<?php

namespace Repas\Voice\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class VoiceException extends DomainException
{
    public const string INVALID_REQUEST = 'INVALID_ALEXA_REQUEST';
    public const string CERTIFICATE_CHAIN_UNAVAILABLE = 'ALEXA_CERTIFICATE_CHAIN_UNAVAILABLE';
    public const string AUTHORIZATION_REFUSED = 'VOICE_AUTHORIZATION_REFUSED';
    public const string LINK_NOT_FOUND = 'VOICE_LINK_NOT_FOUND';
    public const string RATE_LIMITED = 'VOICE_RATE_LIMITED';

    /**
     * @param string $reason raison technique, pour le journal seulement (statut HTTP, délai dépassé, forme) : jamais une donnée de
     *                       la requête, jamais dans la réponse (le message reste la constante)
     */
    private function __construct(string $message, int $code, public readonly string $reason = '')
    {
        parent::__construct($message, $code);
    }

    /**
     * Requête qui ne prouve pas venir d'Alexa, ou qui ne vise pas notre skill (adresse de la chaîne, certificat, signature,
     * horodatage, `applicationId`, corps illisible) : une seule réponse, `400`, et rien d'autre, pour ne rien apprendre à qui
     * essaie (ADR 0029 décision 7).
     */
    public static function invalidRequest(): self
    {
        return new self(self::INVALID_REQUEST, 400);
    }

    /**
     * La chaîne de certificats d'Alexa n'a pas pu être obtenue (réseau, statut, taille, forme, expirée). Levée par le port
     * `CertificateChainFetcher` ; le vérificateur la transforme en `invalidRequest()` : sans certificat, on ne vérifie rien, donc
     * on refuse (échec fermé).
     */
    public static function certificateChainUnavailable(string $reason): self
    {
        return new self(self::CERTIFICATE_CHAIN_UNAVAILABLE, 503, $reason);
    }

    /**
     * La demande d'autorisation de la liaison de compte est refusée (VOX-202) : client inconnu, adresse de retour qui n'est pas
     * exactement l'une de celles autorisées, méthode PKCE autre que `S256`, défi ou `state` mal formé. UNE seule réponse, `422`,
     * sans dire laquelle de ces raisons : l'utilisateur n'y peut rien et un tiers n'apprend rien sur la configuration.
     */
    public static function authorizationRefused(): self
    {
        return new self(self::AUTHORIZATION_REFUSED, 422);
    }

    /**
     * La liaison n'existe pas, ou n'est pas à l'utilisateur qui la retire, `404` (VOX-202).
     */
    public static function linkNotFound(): self
    {
        return new self(self::LINK_NOT_FOUND, 404);
    }

    /**
     * Trop d'appels à la liaison de compte : `voice_token` (par adresse IP) ou `voice_authorize` (par utilisateur), `429`. Levée par
     * `VoiceRateLimitListener` (`Infrastructure`), qui l'enveloppe dans une `TooManyRequestsHttpException` portant `Retry-After`.
     */
    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, 429);
    }
}

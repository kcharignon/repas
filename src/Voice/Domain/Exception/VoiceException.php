<?php

namespace Repas\Voice\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class VoiceException extends DomainException
{
    public const string INVALID_REQUEST = 'INVALID_ALEXA_REQUEST';
    public const string CERTIFICATE_CHAIN_UNAVAILABLE = 'ALEXA_CERTIFICATE_CHAIN_UNAVAILABLE';

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
}

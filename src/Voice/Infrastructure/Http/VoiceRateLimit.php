<?php

namespace Repas\Voice\Infrastructure\Http;


/**
 * Les plafonds de débit de la commande vocale (VOX-202, VOX-204). La valeur est le nom du limiteur déclaré dans
 * `config/packages/rate_limiter.yaml` (`limiter.voice_token`, `limiter.voice_authorize`, `limiter.voice_webhook`), où se trouvent les chiffres.
 */
enum VoiceRateLimit: string
{
    /** `POST /api/voice/oauth/token`, route PUBLIQUE : par adresse IP (30 par heure). */
    case TOKEN = 'voice_token';

    /** `POST /api/v1/voice/alexa/authorize` : par utilisateur du jeton (20 par heure). */
    case AUTHORIZE = 'voice_authorize';

    /**
     * `POST /api/voice/alexa`, route PUBLIQUE : par adresse IP (60 par minute), AVANT la vérification de la signature, car chaque requête dont
     * l'adresse de chaîne est inventée coûte un téléchargement de certificat. Le plafond PAR LIAISON (60 par minute) se pose dans le
     * controller, une fois le jeton résolu : voir `VoiceLinkRateLimiter`.
     */
    case WEBHOOK = 'voice_webhook';

    /** Le compteur est celui de l'utilisateur connecté (sinon celui de l'adresse IP). */
    public function isPerUser(): bool
    {
        return $this === self::AUTHORIZE;
    }

    /**
     * Les en-têtes que porte la réponse `429` en plus de `Retry-After` : celle du point d'accès aux jetons n'est jamais mise en cache, comme
     * toutes les réponses de cette route (RFC 6749 5.1 et 5.2).
     *
     * @return array<string, string>
     */
    public function refusalHeaders(): array
    {
        return $this === self::TOKEN ? ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'] : [];
    }
}

<?php

namespace Repas\Voice\Infrastructure\Http;


/**
 * Les deux plafonds de débit de la liaison de compte (VOX-202). La valeur est le nom du limiteur déclaré dans
 * `config/packages/rate_limiter.yaml` (`limiter.voice_token`, `limiter.voice_authorize`), où se trouvent les chiffres.
 */
enum VoiceRateLimit: string
{
    /** `POST /api/voice/oauth/token`, route PUBLIQUE : par adresse IP (30 par heure). */
    case TOKEN = 'voice_token';

    /** `POST /api/v1/voice/alexa/authorize` : par utilisateur du jeton (20 par heure). */
    case AUTHORIZE = 'voice_authorize';

    /** Le compteur est celui de l'utilisateur connecté (sinon celui de l'adresse IP). */
    public function isPerUser(): bool
    {
        return $this === self::AUTHORIZE;
    }
}

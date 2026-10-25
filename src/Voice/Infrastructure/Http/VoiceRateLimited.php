<?php

namespace Repas\Voice\Infrastructure\Http;


use Attribute;

/**
 * Marque un controller (classe ou méthode) dont chaque appel consomme un jeton du plafond de débit indiqué (voir
 * `VoiceRateLimitListener`). Au-delà du plafond : `429 VOICE_RATE_LIMITED` avec `Retry-After`.
 *
 * `VoiceRoutesAreRateLimitedTest` impose l'attribut à toute route de la liaison de compte, sauf celles qu'il liste comme exceptions.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class VoiceRateLimited
{
    public function __construct(public VoiceRateLimit $limit)
    {
    }
}

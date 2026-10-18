<?php

namespace Repas\Drive\Infrastructure\Http;


use Attribute;

/**
 * Marque un controller (classe ou méthode) dont chaque appel consomme un jeton du plafond de débit de l'utilisateur connecté
 * (voir `DriveRateLimitListener`). Au-delà du plafond : `429 DRIVE_RATE_LIMITED` avec `Retry-After`.
 *
 * `DriveRoutesAreRateLimitedTest` impose l'attribut à toute route du drive, sauf celles qu'il liste comme exceptions.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RateLimitedPerUser
{
    public function __construct(public DriveRateLimit $limit)
    {
    }
}

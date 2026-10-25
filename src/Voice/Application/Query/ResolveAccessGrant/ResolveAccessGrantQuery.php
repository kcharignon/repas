<?php

namespace Repas\Voice\Application\Query\ResolveAccessGrant;


use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Quel compte parle par ce jeton d'accès ? Pour le webhook d'Alexa (VOX-204), qui reçoit le jeton dans la requête. Le jeton est en
 * clair ici (il vient d'arriver) ; il est haché avant toute lecture.
 */
final readonly class ResolveAccessGrantQuery implements QueryInterface
{
    public function __construct(
        public string $accessToken,
    ) {
    }
}

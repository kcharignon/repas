<?php

namespace Repas\Voice\Application\Query\ResolveAccessGrant;


/**
 * Le compte et la liaison d'un jeton d'accès valable. Jamais le jeton, ni son haché.
 */
final readonly class ResolvedAccessGrantDTO
{
    public function __construct(
        public string $userId,
        public string $linkId,
    ) {
    }
}

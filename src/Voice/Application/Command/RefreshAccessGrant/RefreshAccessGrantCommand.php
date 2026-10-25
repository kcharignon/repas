<?php

namespace Repas\Voice\Application\Command\RefreshAccessGrant;


/**
 * Alexa présente son jeton de rafraîchissement pour obtenir une nouvelle paire de jetons (RFC 6749 6). Le client est déjà AUTHENTIFIÉ par
 * la couche HTTP.
 */
final readonly class RefreshAccessGrantCommand
{
    public function __construct(
        public string $refreshToken,
    ) {
    }
}

<?php

namespace Repas\User\Application\RevokeRefreshToken;


/**
 * Déconnexion : supprime la famille du refresh token présenté (par son hachage). Un jeton inconnu n'est pas une erreur.
 */
final readonly class RevokeRefreshTokenCommand
{
    public function __construct(
        public string $tokenHash,
    ) {
    }
}

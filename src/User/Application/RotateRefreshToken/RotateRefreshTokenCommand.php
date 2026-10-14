<?php

namespace Repas\User\Application\RotateRefreshToken;


/**
 * Échange le refresh token présenté (par son hachage) contre son successeur. Voir `RotateRefreshTokenHandler` pour ce que
 * renvoie la commande.
 */
final readonly class RotateRefreshTokenCommand
{
    public function __construct(
        public string $presentedTokenHash,
        public string $nextTokenHash,
    ) {
    }
}

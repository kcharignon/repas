<?php

namespace Repas\Voice\Application\Command;


/**
 * Ce que l'échange d'un code ou d'un jeton de rafraîchissement rend à Alexa (RFC 6749 5.1) : les deux jetons EN CLAIR, qui ne
 * voyagent qu'une fois, dans la réponse qui les donne (la base n'en garde que le haché), et la durée du jeton d'accès en secondes.
 */
final readonly class IssuedTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
    ) {
    }
}

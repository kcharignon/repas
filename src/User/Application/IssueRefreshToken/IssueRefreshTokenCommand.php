<?php

namespace Repas\User\Application\IssueRefreshToken;


/**
 * Ouvre une famille de refresh tokens à la connexion de l'utilisateur. Le secret reste dans le cookie : la commande ne
 * porte que son hachage (`RefreshToken::hash()`), pour qu'il ne passe jamais par le bus (ADR 0015 §1).
 */
final readonly class IssueRefreshTokenCommand
{
    public function __construct(
        public string $userId,
        public string $tokenHash,
    ) {
    }
}

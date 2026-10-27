<?php

namespace Repas\User\Domain\Interface;


use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Plafond des mots de passe refusés d'un utilisateur connecté : tout cas d'usage qui compare un mot de passe saisi à celui du
 * compte demande d'abord `assertAllowed`, puis note chaque refus par `recordFailure`. Un mot de passe juste ne consomme rien.
 */
interface PasswordAttemptLimiter
{
    /**
     * Ne consomme rien : le plafond de cet utilisateur est-il atteint ?
     *
     * @throws RateLimitedException quand trop de mots de passe ont été refusés récemment
     */
    public function assertAllowed(string $userId): void;

    /**
     * Note un mot de passe refusé.
     */
    public function recordFailure(string $userId): void;
}

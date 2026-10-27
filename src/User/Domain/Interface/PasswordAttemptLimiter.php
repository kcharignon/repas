<?php

namespace Repas\User\Domain\Interface;


use Closure;
use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Plafond des mots de passe refusés d'un utilisateur connecté : tout cas d'usage qui compare un mot de passe saisi à celui du
 * compte passe par `attempt`. Un mot de passe juste ne consomme rien.
 */
interface PasswordAttemptLimiter
{
    /**
     * Compare un mot de passe : le plafond de cet utilisateur est lu, la comparaison faite puis, si elle échoue, comptée, le tout sous
     * un verrou propre à l'utilisateur. Deux demandes simultanées pour le même utilisateur se suivent donc : chaque refus est compté
     * avant que la comparaison suivante ne commence, et aucune comparaison n'a lieu au-delà du plafond.
     *
     * @param Closure(): bool $matches la comparaison (vrai : le mot de passe est juste)
     *
     * @return bool le résultat de la comparaison
     *
     * @throws RateLimitedException quand trop de mots de passe ont été refusés récemment (la comparaison n'est pas faite)
     */
    public function attempt(string $userId, Closure $matches): bool;
}

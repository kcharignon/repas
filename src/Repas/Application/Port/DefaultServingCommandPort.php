<?php

namespace Repas\Repas\Application\Port;

/**
 * Le nombre de personnes par défaut d'un compte. La commande vocale (ADR 0029 décision 13, VOX-305) planifie un repas pour un utilisateur
 * qui n'est pas authentifié par son JWT (le jeton d'accès d'Alexa ne porte pas ses réglages). Adapté depuis le contexte `User` (ADR 0035).
 */
interface DefaultServingCommandPort
{
    /** Ce que rend `of()` pour un compte inconnu, ce qui n'arrive pas : le jeton d'accès a été résolu pour un compte actif. */
    public const int FALLBACK = 4;

    /**
     * Le nombre de personnes du compte.
     */
    public function of(string $userId): int;
}

<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Le plafond des lectures de page Marmiton d'un utilisateur (ADR 0037 décision 14) : 20 par heure et par utilisateur du jeton. Il compte les
 * APPELS SORTANTS, pas les imports : une copie d'une recette neutre déjà connue ne le consomme pas. L'état est dans le cache, aucune requête SQL.
 */
interface MarmitonImportLimiter
{
    /**
     * Consomme une lecture de page de ce plafond.
     *
     * @throws RateLimitedException le plafond est atteint (`429 MARMITON_IMPORT_RATE_LIMITED` et le délai d'attente) : rien n'est lu
     */
    public function consume(string $userId): void;
}

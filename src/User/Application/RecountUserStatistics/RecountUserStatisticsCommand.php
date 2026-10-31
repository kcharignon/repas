<?php

namespace Repas\User\Application\RecountUserStatistics;

/**
 * Recompte les statistiques d'un compte (ingrédients créés et recettes) d'après ce que `Repas` en dit : les valeurs REMPLACENT les compteurs.
 */
readonly class RecountUserStatisticsCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}

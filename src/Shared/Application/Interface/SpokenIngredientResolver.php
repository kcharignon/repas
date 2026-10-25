<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\ResolvedIngredient;

/**
 * Port par lequel le contexte `Voice` trouve, parmi les ingrédients de l'utilisateur, celui que désigne une phrase prononcée (ADR 0029
 * décision 3, VOX-102). Défini ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * Le choix est le plus probable, pas le seul possible : la confiance dit comment l'annoncer, et l'erreur se répare dans l'application.
 */
interface SpokenIngredientResolver
{
    /**
     * @param string $phrase la phrase reconnue (« des tomates », « du pain de mie »), telle quelle
     *
     * @return ?ResolvedIngredient un ingrédient de l'utilisateur, jamais celui d'un autre ; `null` si rien ne ressemble à la phrase
     *                             (ou si le compte n'a aucun ingrédient)
     */
    public function resolve(string $userId, string $phrase): ?ResolvedIngredient;
}

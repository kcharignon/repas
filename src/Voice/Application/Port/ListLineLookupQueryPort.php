<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\SpokenListLookupResult;

/**
 * Ce que les lectures vocales demandent à la liste de courses active de l'utilisateur pour une phrase prononcée (ADR 0029 décision 12,
 * VOX-304) : « est-ce qu'il y a des croquettes dans la liste ? ». Adapté depuis le contexte `Repas` (ADR 0035). Le même sens que
 * `ListLineLookupCommandPort`, pour un appelant qui ne fait que lire : rien n'est écrit et aucune liste n'est créée.
 */
interface ListLineLookupQueryPort
{
    /**
     * @param string $phrase la phrase reconnue (« des croquettes », « du pain de mie »), telle quelle
     */
    public function find(string $userId, string $phrase): SpokenListLookupResult;
}

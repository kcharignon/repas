<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\SpokenListLookupResult;

/**
 * Ce que les écritures vocales demandent à la liste de courses active de l'utilisateur pour une phrase prononcée (ADR 0029 décision 12,
 * VOX-304) : « enlève du pain ». Adapté depuis le contexte `Repas` (ADR 0035). Une écriture a besoin de cette lecture dans sa transaction ;
 * une lecture seule passe par `ListLineLookupQueryPort`.
 *
 * Rien n'est écrit et aucune liste n'est créée. Seule la liste ACTIVE est lue : une liste envoyée au drive n'est ni modifiée ni consultée
 * (limite connue, voir `docs/voice/alexa.md`).
 */
interface ListLineLookupCommandPort
{
    /**
     * L'ingrédient de l'utilisateur que la phrase désigne (même mémoire de phrase et même classeur que `IngredientResolutionCommandPort`), cherché
     * parmi les lignes de sa liste active : quand plusieurs ingrédients se valent pour la phrase (même nom dans deux rayons), ceux qui sont
     * DANS la liste passent avant — un seul dans la liste, c'est lui ; plusieurs, ils sont rendus à départager. Jamais de proposition ni de
     * création : un produit inconnu de l'utilisateur est simplement « pas dans la liste ».
     *
     * @param string $phrase la phrase reconnue (« des croquettes », « du pain de mie »), telle quelle
     */
    public function find(string $userId, string $phrase): SpokenListLookupResult;
}

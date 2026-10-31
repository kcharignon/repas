<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\SpokenMealLookupResult;

/**
 * Ce que les écritures vocales demandent aux repas de la liste de courses active de l'utilisateur pour une phrase prononcée (ADR 0029
 * décision 12, VOX-306) : « enlève le repas hachis parmentier ». Adapté depuis le contexte `Repas` (ADR 0035). Rien n'est écrit et aucune liste
 * n'est créée ; une liste envoyée au drive n'est ni modifiée ni consultée.
 */
interface ListMealLookupCommandPort
{
    /**
     * Le repas (la recette planifiée) de la liste active de l'utilisateur que la phrase désigne. Seuls les repas PLANIFIÉS sont cherchés (jamais
     * les recettes de l'utilisateur qui ne sont pas dans la liste), avec le même classeur de noms que les produits et la mémoire de phrase des
     * recettes. Aucun repas sûr : les repas planifiés qui ressemblent à la phrase (trois au plus), jamais devinés.
     *
     * @param string $phrase la phrase reconnue (« hachis parmentier »), sans le mot « repas » ni « recette »
     */
    public function find(string $userId, string $phrase): SpokenMealLookupResult;
}

<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Shared\Application\Dto\SpokenMealLookupResult;

/**
 * Port de LECTURE par lequel le contexte `Voice` regarde ce que la liste de courses active de l'utilisateur contient pour une phrase prononcée
 * (ADR 0029 décision 12, VOX-304) : « est-ce qu'il y a des croquettes dans la liste ? », « enlève du pain ». Défini ici, implémenté dans
 * `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * Rien n'est écrit et aucune liste n'est créée. Seule la liste ACTIVE est lue : une liste envoyée au drive n'est ni modifiée ni consultée
 * (limite connue, voir `docs/voice/alexa.md`).
 */
interface SpokenListLookup
{
    /**
     * L'ingrédient de l'utilisateur que la phrase désigne (même mémoire de phrase et même classeur que `SpokenIngredientResolver`), cherché
     * parmi les lignes de sa liste active : quand plusieurs ingrédients se valent pour la phrase (même nom dans deux rayons), ceux qui sont
     * DANS la liste passent avant — un seul dans la liste, c'est lui ; plusieurs, ils sont rendus à départager. Jamais de proposition ni de
     * création : un produit inconnu de l'utilisateur est simplement « pas dans la liste ».
     *
     * @param string $phrase la phrase reconnue (« des croquettes », « du pain de mie »), telle quelle
     */
    public function find(string $userId, string $phrase): SpokenListLookupResult;

    /**
     * Le repas (la recette planifiée) de la liste active de l'utilisateur que la phrase désigne (ADR 0029 décision 12, VOX-306) : « enlève le repas
     * hachis parmentier ». Seuls les repas PLANIFIÉS sont cherchés (jamais les recettes de l'utilisateur qui ne sont pas dans la liste), avec le même
     * classeur de noms et la même mémoire de phrase des recettes que `SpokenRecipeResolver`. Aucun repas sûr : les repas planifiés qui ressemblent à la
     * phrase (trois au plus), jamais devinés. Rien n'est écrit, aucune liste n'est créée.
     *
     * @param string $phrase la phrase reconnue (« hachis parmentier »), sans le mot « repas » ni « recette »
     */
    public function findMeal(string $userId, string $phrase): SpokenMealLookupResult;
}

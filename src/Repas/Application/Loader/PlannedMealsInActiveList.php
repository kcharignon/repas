<?php

namespace Repas\Repas\Application\Loader;

use Repas\Shared\Application\Dto\ListedMeal;

/**
 * Port de lecture des repas de la liste de courses ACTIVE d'un utilisateur, de quoi lui répondre à voix haute (ADR 0029 décision 12, VOX-306).
 */
interface PlannedMealsInActiveList
{
    /**
     * Les repas de la liste active (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une), chacun avec l'identifiant et le
     * nom de sa recette et son nombre de personnes. Deux requêtes, quel que soit le nombre de repas.
     *
     * @return ?list<ListedMeal> `null` : l'utilisateur n'a pas de liste active ; une liste active sans repas donne une liste vide
     */
    public function forUser(string $userId): ?array;
}

<?php

namespace Repas\Repas\Domain\Interface;

use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\ActiveListMeal;

/**
 * Le contenu de la liste de courses ACTIVE d'un utilisateur (ses lignes, ses repas), pour un appelant qui écrit et en tire un choix dans sa
 * transaction (`ActiveListLookup`, ADR 0029 décision 12). Lecture légère : des valeurs, aucun modèle hydraté.
 *
 * Un port d'écriture (lu par un `CommandHandler` ou un Open Host Service d'écriture, ADR 0035) : il ne s'appelle donc pas `…Loader`, nom réservé aux
 * ports de lecture des `QueryHandler`. Le Loader `ActiveShoppingListLines` rend les mêmes lignes
 * (`ActiveShoppingListReader`).
 */
interface ActiveShoppingListContents
{
    /**
     * Les lignes de la liste active (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une). Deux requêtes, quel que
     * soit le nombre de lignes.
     *
     * @return ?list<ActiveListLine> `null` : l'utilisateur n'a pas de liste active ; une liste active vide donne une liste vide
     */
    public function lines(string $userId): ?array;

    /**
     * Les repas de la liste active, chacun avec l'identifiant et le nom de sa recette et son nombre de personnes. Deux requêtes, quel que soit le
     * nombre de repas.
     *
     * @return ?list<ActiveListMeal> `null` : l'utilisateur n'a pas de liste active ; une liste active sans repas donne une liste vide
     */
    public function meals(string $userId): ?array;

    /**
     * L'utilisateur a une liste envoyée au drive (statut `SENT`) : de quoi dire « ta liste est déjà partie » plutôt que « pas de liste en cours »
     * quand il n'en a pas d'active. Une seule requête ; la liste envoyée n'est jamais lue.
     */
    public function hasSentList(string $userId): bool;
}

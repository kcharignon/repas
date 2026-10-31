<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Domain\Model\ActiveListLine;

/**
 * Port de lecture des lignes de la liste de courses ACTIVE d'un utilisateur, de quoi lui répondre à voix haute (ADR 0029 décision 12, VOX-304).
 */
interface ActiveShoppingListLines
{
    /**
     * Les lignes de la liste active (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une), chacune avec le nom de
     * son ingrédient, son rayon et le nom de son unité. Deux requêtes, quel que soit le nombre de lignes.
     *
     * @return ?list<ActiveListLine> `null` : l'utilisateur n'a pas de liste active ; une liste active vide donne une liste vide
     */
    public function forUser(string $userId): ?array;

    /**
     * L'utilisateur a une liste envoyée au drive (statut `SENT`) : de quoi dire « ta liste est déjà partie » plutôt que « pas de liste en cours »
     * quand il n'en a pas d'active. Une seule requête ; la liste envoyée n'est jamais lue.
     */
    public function hasSentList(string $userId): bool;
}

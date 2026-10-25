<?php

namespace Repas\Repas\Application\Loader;

use Repas\Shared\Application\Dto\ListedLine;

/**
 * Port de lecture des lignes de la liste de courses ACTIVE d'un utilisateur, de quoi lui répondre à voix haute (ADR 0029 décision 12, VOX-304).
 */
interface ActiveShoppingListLines
{
    /**
     * Les lignes de la liste active (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une), chacune avec le nom de
     * son ingrédient, son rayon et le nom de son unité. Deux requêtes, quel que soit le nombre de lignes.
     *
     * @return ?list<ListedLine> `null` : l'utilisateur n'a pas de liste active ; une liste active vide donne une liste vide
     */
    public function forUser(string $userId): ?array;
}

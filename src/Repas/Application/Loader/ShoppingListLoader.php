<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;

interface ShoppingListLoader
{
    /**
     * Listes de courses d'un utilisateur, de la plus récente à la plus ancienne.
     *
     * @return list<ShoppingListDTO>
     */
    public function listByOwner(string $ownerId): array;

    /**
     * Ce que contient la liste active d'un utilisateur : quantité par ingrédient et recettes planifiées.
     * Sans liste active, un résumé vide (identifiant null) : ce n'est pas une erreur.
     */
    public function getActiveSummary(string $ownerId): ActiveShoppingListSummaryDTO;
}

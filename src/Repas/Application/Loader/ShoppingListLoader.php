<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;

interface ShoppingListLoader
{
    /**
     * Listes de courses d'un utilisateur, de la plus récente à la plus ancienne.
     *
     * @return list<ShoppingListDTO>
     */
    public function listByOwner(string $ownerId): array;
}

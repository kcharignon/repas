<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ShoppingListInMemoryLoader implements ShoppingListLoader
{
    /** @var list<string> identifiants de propriétaire reçus, dans l'ordre des appels */
    public array $listByOwnerCalls = [];

    /**
     * @param list<ShoppingListDTO> $shoppingLists
     */
    public function __construct(
        private readonly array $shoppingLists = [],
    ) {
    }

    public function listByOwner(string $ownerId): array
    {
        $this->listByOwnerCalls[] = $ownerId;

        return $this->shoppingLists;
    }
}

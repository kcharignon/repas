<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ShoppingListInMemoryLoader implements ShoppingListLoader
{
    /** @var list<string> identifiants de propriétaire reçus, dans l'ordre des appels */
    public array $listByOwnerCalls = [];

    /** @var list<string> identifiants de propriétaire reçus, dans l'ordre des appels */
    public array $getActiveSummaryCalls = [];

    /**
     * @param list<ShoppingListDTO> $shoppingLists
     */
    public function __construct(
        private readonly array $shoppingLists = [],
        private readonly ?ActiveShoppingListSummaryDTO $activeSummary = null,
    ) {
    }

    public function listByOwner(string $ownerId): array
    {
        $this->listByOwnerCalls[] = $ownerId;

        return $this->shoppingLists;
    }

    public function getActiveSummary(string $ownerId): ActiveShoppingListSummaryDTO
    {
        $this->getActiveSummaryCalls[] = $ownerId;

        return $this->activeSummary ?? new ActiveShoppingListSummaryDTO(null, [], []);
    }
}

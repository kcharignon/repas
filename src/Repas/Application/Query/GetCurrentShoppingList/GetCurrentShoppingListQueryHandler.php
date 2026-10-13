<?php

namespace Repas\Repas\Application\Query\GetCurrentShoppingList;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * La liste « en cours » d'un utilisateur : l'active, à défaut la plus récente, null s'il n'en a aucune.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCurrentShoppingListQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    public function __invoke(GetCurrentShoppingListQuery $query): ?ShoppingListDetailDTO
    {
        return $this->shoppingListLoader->getCurrent($query->ownerId);
    }
}

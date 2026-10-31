<?php

namespace Repas\Repas\Application\Query\GetShoppingListType;

use Repas\Repas\Application\Loader\ShoppingListTypeLoader;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetShoppingListTypeQueryHandler
{
    public function __construct(
        private ShoppingListTypeLoader $listTypeLoader,
    ) {
    }

    /**
     * @throws ShoppingListTypeException le type est inconnu, ou il est à un autre utilisateur
     */
    public function __invoke(GetShoppingListTypeQuery $query): ShoppingListTypeDTO
    {
        return $this->listTypeLoader->get($query->id, $query->userId);
    }
}

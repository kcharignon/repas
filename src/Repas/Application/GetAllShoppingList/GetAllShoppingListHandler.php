<?php

namespace Repas\Repas\Application\GetAllShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetAllShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    )
    {
    }

    public function __invoke(GetAllShoppingListQuery $query): array
    {
        return $this->shoppingListRepository->findByOwner($query->owner)->toArray();
    }
}

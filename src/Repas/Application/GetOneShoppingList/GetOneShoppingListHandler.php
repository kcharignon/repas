<?php

namespace Repas\Repas\Application\GetOneShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetOneShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(GetOneShoppingListQuery $query)
    {
        return $this->shoppingListRepository->find($query->shoppingListId);
    }

}

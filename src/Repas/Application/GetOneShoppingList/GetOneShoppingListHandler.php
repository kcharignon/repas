<?php

namespace Repas\Repas\Application\GetOneShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetOneShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(GetOneShoppingListQuery $query): ShoppingList
    {
        return $this->shoppingListRepository->getOneById($query->shoppingListId);
    }

}

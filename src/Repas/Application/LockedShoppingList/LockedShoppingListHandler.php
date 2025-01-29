<?php

namespace Repas\Repas\Application\LockedShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class LockedShoppingListHandler
{


    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(LockedShoppingListCommand $command): ShoppingList
    {
        // Recuperation de la liste de course si elle existe
        $shoppingList = $this->shoppingListRepository->getOneById($command->shoppingListId);
        // Verrouille la liste de course
        $shoppingList->lock();
        $this->shoppingListRepository->save($shoppingList);
        return $shoppingList;
    }
}

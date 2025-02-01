<?php

namespace Repas\Repas\Application\AdvanceShoppingListToShopping;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AdvanceShoppingListToShoppingHandler
{


    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     */
    public function __invoke(AdvanceShoppingListToShoppingCommand $command): ShoppingList
    {
        // Recuperation de la liste de course si elle existe
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->toShopping();
        $this->shoppingListRepository->save($shoppingList);
        return $shoppingList;
    }
}

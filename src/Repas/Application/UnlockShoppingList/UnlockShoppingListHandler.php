<?php

namespace Repas\Repas\Application\UnlockShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UnlockShoppingListHandler
{
    public function __construct(private ShoppingListRepository $shoppingListRepository)
    {
    }


    public function __invoke(UnlockShoppingListCommand $command): ShoppingList
    {
        // Recuperation de la liste de course active
        $shoppingListUnlocked = $this->shoppingListRepository->getOneActiveByOwner($command->owner);
        // Verrouille la liste de course active si elle existe
        if ($shoppingListUnlocked instanceof ShoppingList) {
            $shoppingListUnlocked->lock();
            $this->shoppingListRepository->save($shoppingListUnlocked);
        }
        // Recuperation la liste de course
        $shoppingListLocked = $this->shoppingListRepository->getOneById($command->shoppingListId);
        // Deverrouille la liste de course
        $shoppingListLocked->unlock();
        $this->shoppingListRepository->save($shoppingListLocked);
        return $shoppingListLocked;
    }

}

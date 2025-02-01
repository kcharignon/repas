<?php

namespace Repas\Repas\Application\RevertShoppingListToPlanning;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RevertShoppingListToPlanningHandler
{
    public function __construct(private ShoppingListRepository $shoppingListRepository)
    {
    }


    /**
     * @throws ShoppingListException
     */
    public function __invoke(RevertShoppingListToPlanningCommand $command): ShoppingList
    {
        // Recuperation de la liste de course active
        $shoppingListUnlocked = $this->shoppingListRepository->findOnePlanningByOwner($command->owner);
        // Passe la liste de course à SHOPPING si elle existe
        if ($shoppingListUnlocked instanceof ShoppingList) {
            $shoppingListUnlocked->toShopping();
            $this->shoppingListRepository->save($shoppingListUnlocked);
        }
        // Recuperation la liste de course
        $planningShoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        // Passe la liste de course à planning
        $planningShoppingList->toPlanning();
        $this->shoppingListRepository->save($planningShoppingList);
        return $planningShoppingList;
    }

}

<?php

namespace Repas\Repas\Application\ActivatedShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ActivatedShoppingListHandler
{

    public function __construct(
        public ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(ActivatedShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);

        // Si le propriétaire a déjà une liste active on la met en pause.
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($shoppingList->getOwner());
        if ($activeShoppingList instanceof ShoppingList) {
            $activeShoppingList->pause();
            $this->shoppingListRepository->save($activeShoppingList);
        }

        $shoppingList->activated();
        $this->shoppingListRepository->save($shoppingList);
    }
}

<?php

namespace Repas\Repas\Application\TickLineOnShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class TickLineOnShoppingListHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(TickLineOnShoppingListCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $row->tick();
        $this->shoppingListRowRepository->save($row);

        // Si on a coché toute la liste, on la passe au status terminé.
        $shoppingList = $this->shoppingListRepository->findOneById($row->getShoppingListId());
        if ($shoppingList->allLineTicked()) {
            $shoppingList->completed();
            $this->shoppingListRepository->save($shoppingList);
        }
    }
}

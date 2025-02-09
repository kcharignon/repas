<?php

namespace Repas\Repas\Application\UncheckLineOnShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UncheckLineOnShoppingListHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(UncheckLineOnShoppingListCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $row->uncheck();
        $this->shoppingListRowRepository->save($row);

        // Si la liste est au status terminé, on la repasse en active
        $shoppingList = $this->shoppingListRepository->findOneById($row->getShoppingListId());
        if ($shoppingList->isCompleted()) {
            $shoppingList->activated();
            $this->shoppingListRepository->save($shoppingList);
        }

    }
}

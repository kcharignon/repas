<?php

namespace Repas\Repas\Application\RemoveShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(RemoveShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);

        $this->shoppingListRepository->delete($shoppingList);
    }
}

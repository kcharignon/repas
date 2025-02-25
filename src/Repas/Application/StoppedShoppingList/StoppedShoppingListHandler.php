<?php

namespace Repas\Repas\Application\StoppedShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class StoppedShoppingListHandler
{

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(StoppedShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->completed();
        $this->shoppingListRepository->save($shoppingList);
    }
}

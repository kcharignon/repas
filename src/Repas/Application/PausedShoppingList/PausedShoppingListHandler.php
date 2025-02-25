<?php

namespace Repas\Repas\Application\PausedShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class PausedShoppingListHandler
{

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(PausedShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->pause();
        $this->shoppingListRepository->save($shoppingList);
    }
}

<?php

namespace Repas\Repas\Application\UpdateShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateShoppingListHandler
{

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(UpdateShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->setName($command->newName);
        $this->shoppingListRepository->save($shoppingList);
    }
}

<?php

namespace Repas\Repas\Application\StoppedShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class StoppedShoppingListHandler
{

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(StoppedShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->completed();
        $this->shoppingListRepository->save($shoppingList);

        $owner = $shoppingList->getOwner();
        $owner->completedShoppingList($shoppingList);
        $this->userRepository->save($owner);
    }
}

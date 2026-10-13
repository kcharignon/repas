<?php

namespace Repas\Repas\Application\Command\EnsureActiveShoppingList;


use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * S'assure que l'utilisateur a une liste active : ne fait rien s'il en a une, sinon en crée une (la création
 * vérifie que l'utilisateur existe).
 */
#[AsMessageHandler]
readonly class EnsureActiveShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private CreateShoppingListHandler $createShoppingListHandler,
    ) {
    }

    public function __invoke(EnsureActiveShoppingListCommand $command): void
    {
        if ($this->shoppingListRepository->hasActiveShoppingList($command->ownerId)) {
            return;
        }

        ($this->createShoppingListHandler)(new CreateShoppingListCommand($command->shoppingListId, $command->ownerId));
    }
}

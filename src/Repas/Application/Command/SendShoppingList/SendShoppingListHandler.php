<?php

namespace Repas\Repas\Application\Command\SendShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->assertVersion($command->expectedVersion);

        // Déjà envoyée : sans effet, rien n'est enregistré (la version ne bouge pas)
        if ($shoppingList->isSent()) {
            return;
        }

        $shoppingList->send($this->clock->now());
        $this->shoppingListRepository->save($shoppingList);
    }
}

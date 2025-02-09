<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class RemoveShoppingListCompletedEventListener
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }


    public function __invoke(NewShoppingListCreatedEvent $event): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($event->shoppingListId);

        $shoppingLists = $this->shoppingListRepository->findByOwnerAndStatus($shoppingList->getOwner(), ShoppingListStatus::COMPLETED);
        foreach ($shoppingLists as $shoppingList) {
            $this->commandBus->dispatch(new RemoveShoppingListCommand($shoppingList->getId()));
        }
    }
}

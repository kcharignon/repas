<?php

namespace Repas\Repas\Application\TickLineOnShoppingList;

use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class TickLineOnShoppingListHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(TickLineOnShoppingListCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $row->tick();
        $this->shoppingListRowRepository->save($row);

        $this->eventDispatcher->dispatch(new LineTickedEvent($row->getShoppingListId()));
    }
}

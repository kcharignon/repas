<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class StopListEventListener
{
    public function __construct(
        public ShoppingListRowRepository $shoppingListRowRepository,
        public CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(LineTickedEvent $lineTickedEvent): void
    {
        if ($this->shoppingListRowRepository->allRowsChecked($lineTickedEvent->shoppingListId)) {
            $command = new StoppedShoppingListCommand($lineTickedEvent->shoppingListId);
            $this->commandBus->dispatch($command);
        }
    }
}

<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class StopListEventListener
{
    public function __construct(
        public ShoppingListRepository $shoppingListRepository,
        public CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(LineTickedEvent $lineTickedEvent): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($lineTickedEvent->shoppingListId);

        if ($shoppingList->allLineTicked()) {
            $command = new StoppedShoppingListCommand($shoppingList->getId());
            $this->commandBus->dispatch($command);
        }
    }
}

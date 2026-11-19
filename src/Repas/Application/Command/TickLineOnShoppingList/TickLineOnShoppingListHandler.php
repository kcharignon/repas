<?php

namespace Repas\Repas\Application\Command\TickLineOnShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class TickLineOnShoppingListHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(TickLineOnShoppingListCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        // Une liste envoyée au drive n'est plus modifiable (ADR 0023) : on ne charge que la ligne, la liste se demande par un port booléen
        if ($this->shoppingListRepository->isSent($row->getShoppingListId())) {
            throw ShoppingListException::sent($row->getShoppingListId());
        }

        // La ligne garde qu'elle a été cochée à la main, et quand (ADR 0041)
        $row->tick($this->clock->now());
        $this->shoppingListRowRepository->save($row);

        $this->eventDispatcher->dispatch(new LineTickedEvent($row->getShoppingListId()));
    }
}

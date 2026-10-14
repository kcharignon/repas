<?php

namespace Repas\Repas\Application\Command\CreateShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     */
    public function __invoke(CreateShoppingListCommand $query): void
    {
        // Met en pause la liste si elle existe
        $activateShoppingList = $this->shoppingListRepository->findOneActivateByOwner($query->ownerId);
        if ($activateShoppingList instanceof ShoppingList) {
            $activateShoppingList->pause();
            $this->shoppingListRepository->save($activateShoppingList);
        }

        // Création d'une nouvelle liste (active)
        $shoppingList = ShoppingList::create(
            id: $query->shoppingListId,
            ownerId: $query->ownerId,
            createdAt: $this->clock->now(),
        );

        $this->shoppingListRepository->save($shoppingList);

        // Cet event est écouté par un eventListener qu'il supprime les listes terminées
        $this->eventDispatcher->dispatch(new NewShoppingListCreatedEvent($shoppingList->getId(), $query->ownerId));
    }
}

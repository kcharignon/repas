<?php

namespace Repas\Repas\Application\CreateShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateShoppingListHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(CreateShoppingListCommand $query): void
    {
        $owner = $this->userRepository->findOneById($query->ownerId);
        // Met en pause la liste si elle existe
        $activateShoppingList = $this->shoppingListRepository->findOneActivateByOwner($owner);
        if ($activateShoppingList instanceof ShoppingList) {
            $activateShoppingList->pause();
            $this->shoppingListRepository->save($activateShoppingList);
        }

        // Création d'une nouvelle liste (active)
        $shoppingList = ShoppingList::create(
            id: $query->shoppingListId,
            owner: $owner,
            createdAt: $this->clock->now(),
        );

        $this->shoppingListRepository->save($shoppingList);

        // Cet event est écouté par un eventListener qu'il supprime les listes terminées
        $this->eventDispatcher->dispatch(new NewShoppingListCreatedEvent($shoppingList->getId()));
    }
}

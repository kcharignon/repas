<?php

namespace Repas\Repas\Application\CreateShoppingList;

use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Clock;
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
        private Clock $clock,
    ) {
    }

    /**
     * @throws UserException
     * @throws ShoppingListException
     */
    public function __invoke(CreateShoppingListCommand $query): void
    {
        $owner = $this->userRepository->findOneById($query->ownerId);
        // Supprime la liste si elle existe
        $activateShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);
        if ($activateShoppingList instanceof ShoppingList) {
            $this->shoppingListRepository->delete($activateShoppingList);
        }

        // Création d'une nouvelle liste (active)
        $shoppingList = ShoppingList::create(
            id: $query->shoppingListId,
            owner: $owner,
            createdAt: $this->clock->now(),
        );

        $this->shoppingListRepository->save($shoppingList);

        // Cet event est écouté par un eventListener qui supprime les listes terminées
        $this->eventDispatcher->dispatch(new NewShoppingListCreatedEvent($shoppingList->getId()));
    }
}

<?php

namespace Repas\Repas\Application\CreateShoppingList;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Clock;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateShoppingListHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ShoppingListRepository $shoppingListRepository,
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
        // Passe la liste en SHOPPING si elle existe
        $activateShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);
        if ($activateShoppingList instanceof ShoppingList) {
            $activateShoppingList->toShopping();
            $this->shoppingListRepository->save($activateShoppingList);
        }

        // Création d'une nouvelle liste (active)
        $shoppingList = ShoppingList::create(
            id: $query->shoppingListId,
            owner: $owner,
            createdAt: $this->clock->now(),
        );

        $this->shoppingListRepository->save($shoppingList);
    }
}

<?php

namespace Repas\Repas\Application\Command\StoppedShoppingList;

use Repas\Shared\Domain\Event\ShoppingListCompletedEvent;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class StoppedShoppingListHandler
{

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(StoppedShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->completed();
        $this->shoppingListRepository->save($shoppingList);

        // La statistique du propriétaire est tenue par le contexte `User` (UpdateUserStatisticsEventListener) : il recharge
        // l'utilisateur lui-même, ce handler n'enregistre plus de modèle `User` (ADR 0013 règles 7 et 8)
        $this->eventDispatcher->dispatch(new ShoppingListCompletedEvent(
            ownerId: $shoppingList->getOwnerId(),
            shoppingListId: $shoppingList->getId(),
            createdAt: $shoppingList->getCreatedAt(),
            mealCount: $shoppingList->getMeals()->count(),
            ingredientCount: $shoppingList->getIngredients()->count(),
            rowCount: $shoppingList->getRows()->count(),
        ));
    }
}

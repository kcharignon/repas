<?php

namespace Repas\User\Infrastructure\EventListener;


use Repas\Shared\Domain\Event\IngredientCreatedEvent;
use Repas\Shared\Domain\Event\IngredientRemovedEvent;
use Repas\Shared\Domain\Event\RecipeCreatedEvent;
use Repas\Shared\Domain\Event\RecipeRemovedEvent;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Event\RecipesOrIngredientsRemovedEvent;
use Repas\Shared\Domain\Event\ShoppingListCompletedEvent;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\RecordCompletedShoppingList\RecordCompletedShoppingListCommand;
use Repas\User\Application\UpdateUserStatistics\UpdateUserStatisticsCommand;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: RecipesOrIngredientsCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipeCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: IngredientCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipesOrIngredientsRemovedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipeRemovedEvent::class, method: '__invoke')]
#[AsEventListener(event: IngredientRemovedEvent::class, method: '__invoke')]
#[AsEventListener(event: ShoppingListCompletedEvent::class, method: 'onShoppingListCompleted')]
readonly class UpdateUserStatisticsEventListener
{

    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(RecipesOrIngredientsCreatedEvent|RecipesOrIngredientsRemovedEvent $event): void
    {
        if ($event instanceof RecipesOrIngredientsCreatedEvent) {
            $command = new UpdateUserStatisticsCommand($event->userId, $event->ingredientSlugs->count(), $event->recipeIds->count());
        } else {
            $command = new UpdateUserStatisticsCommand($event->userId, -$event->ingredientSlugs->count(), -$event->recipeIds->count());
        }
        $this->commandBus->dispatch($command);
    }

    public function onShoppingListCompleted(ShoppingListCompletedEvent $event): void
    {
        $this->commandBus->dispatch(new RecordCompletedShoppingListCommand(
            userId: $event->ownerId,
            shoppingListId: $event->shoppingListId,
            createdAt: $event->createdAt,
            mealCount: $event->mealCount,
            ingredientCount: $event->ingredientCount,
            rowCount: $event->rowCount,
        ));
    }
}

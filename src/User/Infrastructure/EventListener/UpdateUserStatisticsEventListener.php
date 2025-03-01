<?php

namespace Repas\User\Infrastructure\EventListener;


use Repas\Repas\Domain\Event\IngredientCreatedEvent;
use Repas\Repas\Domain\Event\IngredientRemovedEvent;
use Repas\Repas\Domain\Event\RecipeCreatedEvent;
use Repas\Repas\Domain\Event\RecipeRemovedEvent;
use Repas\Repas\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Repas\Domain\Event\RecipesOrIngredientsRemovedEvent;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\UpdateUserStatistics\UpdateUserStatisticsCommand;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: RecipesOrIngredientsCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipeCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: IngredientCreatedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipesOrIngredientsRemovedEvent::class, method: '__invoke')]
#[AsEventListener(event: RecipeRemovedEvent::class, method: '__invoke')]
#[AsEventListener(event: IngredientRemovedEvent::class, method: '__invoke')]
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
}

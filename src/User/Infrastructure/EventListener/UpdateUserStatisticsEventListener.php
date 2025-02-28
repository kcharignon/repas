<?php

namespace Repas\User\Infrastructure\EventListener;


use Repas\Repas\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\UpdateUserStatistics\UpdateUserStatisticsCommand;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

readonly class UpdateUserStatisticsEventListener
{

    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    #[AsEventListener]
    public function __invoke(RecipesOrIngredientsCreatedEvent $event): void
    {
        $command = new UpdateUserStatisticsCommand($event->userId, $event->ingredientSlugs->count(), $event->recipeIds->count());
        $this->commandBus->dispatch($command);
    }
}

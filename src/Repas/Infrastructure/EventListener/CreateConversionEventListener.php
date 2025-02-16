<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\CreateConversion\CreateConversionCommand;
use Repas\Repas\Domain\Event\CreateIngredientWithConversionEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class CreateConversionEventListener
{

    public function __construct(
        private CommandBusInterface $commandBus,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(CreateIngredientWithConversionEvent $event): void
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($event->ingredientSlug);

        $command = new CreateConversionCommand(
            startUnitSlug: $ingredient->getDefaultPurchaseUnit()->getSlug(),
            endUnitSlug: $ingredient->getDefaultCookingUnit()->getSlug(),
            coefficient: $event->coefficient,
            ingredientSlug: $ingredient->getSlug(),
        );

        $this->commandBus->dispatch($command);
    }
}

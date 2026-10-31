<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Repas\Domain\Interface\IngredientSeedingFeature;
use Repas\Shared\Domain\Event\UserActivatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Un compte devient actif : il reçoit une copie des ingrédients « de base » du propriétaire du catalogue (ADR 0028, amendement du 2026-10-20,
 * ING-008). L'écoute est synchrone, donc dans la transaction de l'activation : si l'amorçage échoue, l'activation échoue avec lui (décision
 * D6) et la personne réessaie, rien n'est fait à moitié. Jamais attrapée ici pour « continuer quand même ».
 *
 * L'interrupteur `INGREDIENT_SEEDING_ENABLED` (coupé par défaut) ne commande que ce chemin automatique : coupé, l'activation réussit sans
 * amorçage, sans requête de plus, et le compte reste non amorcé (la commande `repas:ingredients:seed` le servira plus tard).
 */
#[AsEventListener]
readonly class SeedIngredientsOnUserActivatedListener
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private IngredientSeedingFeature $feature,
    ) {
    }

    public function __invoke(UserActivatedEvent $event): void
    {
        if (!$this->feature->isEnabled()) {
            return;
        }

        $this->commandBus->dispatch(new SeedUserIngredientsCommand($event->userId, SeedUserIngredientsCommand::SOURCE_ACTIVATION));
    }
}

<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\VoiceMealPlanner;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Adaptateur du port `VoiceMealPlanner` : une commande sur le bus transactionnel, dont le handler renvoie le résultat (ADR 0015 §12).
 */
final readonly class VoiceMealPlannerAdapter implements VoiceMealPlanner
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function plan(string $userId, string $recipeId, ?int $servings = null): PlannedSpokenMeal
    {
        $result = $this->commandBus->dispatch(new PlanSpokenMealCommand($userId, $recipeId, UuidGenerator::new(), $servings));
        if (!$result instanceof PlannedSpokenMeal) {
            throw new \LogicException('PlanSpokenMealHandler doit renvoyer un PlannedSpokenMeal.');
        }

        return $result;
    }

    public function changeServings(string $userId, string $recipeId, int $servings): ChangedMealServings
    {
        $result = $this->commandBus->dispatch(new ChangeSpokenMealServingsCommand($userId, $recipeId, $servings));
        if (!$result instanceof ChangedMealServings) {
            throw new \LogicException('ChangeSpokenMealServingsHandler doit renvoyer un ChangedMealServings.');
        }

        return $result;
    }
}

<?php

namespace Repas\Repas\Application\OpenHostService;

use LogicException;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Repas\Domain\Model\MealPlanResult;
use Repas\Repas\Domain\Model\MealRemovalResult;
use Repas\Repas\Domain\Model\MealServingsResult;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : planifier un repas (une recette de l'utilisateur) dans sa liste de courses (ADR 0029
 * décision 13, VOX-305), changer son nombre de personnes, le retirer ou en baisser le nombre (décision 12, VOX-306). Chaque appel est une commande sur
 * le bus transactionnel, dont le handler renvoie ce qu'il a fait (ADR 0015 §12) : un échec n'écrit rien.
 *
 * La liste active reçoit le repas et ses ingrédients dans les bonnes proportions ; sans liste active (aucune, ou envoyée au drive), une nouvelle liste
 * est créée. Une liste envoyée n'est jamais modifiée. Une recette n'est jamais deux fois dans une liste.
 */
final readonly class MealPlanCommandOHS
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * @param ?int $servings le nombre de personnes dit (1 à 99) ; `null` : celui du compte
     *
     * @return MealPlanResult `alreadyPlanned` vrai (rien n'est écrit, aucune liste n'est créée) quand la recette est déjà dans la liste active
     *
     * @throws \Repas\Repas\Domain\Exception\MealPlanningException la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient de la recette
     *                                                              ne se convertit pas dans son unité d'achat (rien n'est écrit)
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function plan(string $userId, string $recipeId, ?int $servings = null): MealPlanResult
    {
        $result = $this->commandBus->dispatch(new PlanSpokenMealCommand($userId, $recipeId, UuidGenerator::new(), $servings));
        if (!$result instanceof MealPlanResult) {
            throw new LogicException('PlanSpokenMealHandler doit renvoyer un MealPlanResult.');
        }

        return $result;
    }

    /**
     * Fixe le NOUVEAU TOTAL de personnes d'un repas déjà dans la liste active : les lignes des ingrédients sont ajustées de la différence.
     *
     * @param int $servings le nouveau total, de 1 à 99
     *
     * @return MealServingsResult `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou si la recette n'y est plus
     *
     * @throws \Repas\Repas\Domain\Exception\MealPlanningException la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient ne se convertit pas
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function changeServings(string $userId, string $recipeId, int $servings): MealServingsResult
    {
        $result = $this->commandBus->dispatch(new ChangeSpokenMealServingsCommand($userId, $recipeId, $servings));
        if (!$result instanceof MealServingsResult) {
            throw new LogicException('ChangeSpokenMealServingsHandler doit renvoyer un MealServingsResult.');
        }

        return $result;
    }

    /**
     * Retire un repas de la liste active, ou en baisse le nombre de personnes : sans `$servings` le repas entier quitte la liste ; avec, ce nombre de
     * personnes est enlevé (4 moins 2 : 2), et le repas quitte la liste quand on en enlève autant ou plus (jamais un nombre de personnes nul ou
     * négatif). Les lignes des ingrédients diminuent de ce que le repas apportait. Une liste envoyée au drive n'est jamais modifiée.
     *
     * @param ?int $servings le nombre de personnes à enlever (1 à 99) ; `null` : tout le repas
     *
     * @return MealRemovalResult `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou si la recette n'y est plus
     *
     * @throws \Repas\Repas\Domain\Exception\MealPlanningException la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient ne se convertit pas
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function removeMeal(string $userId, string $recipeId, ?int $servings = null): MealRemovalResult
    {
        $result = $this->commandBus->dispatch(new RemoveSpokenMealCommand($userId, $recipeId, $servings));
        if (!$result instanceof MealRemovalResult) {
            throw new LogicException('RemoveSpokenMealHandler doit renvoyer un MealRemovalResult.');
        }

        return $result;
    }
}

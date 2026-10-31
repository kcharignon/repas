<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\ChangedMealServings;
use Repas\Voice\Domain\Model\PlannedSpokenMeal;
use Repas\Voice\Domain\Model\RemovedSpokenMeal;

/**
 * Ce que les écritures vocales demandent à la liste de courses de l'utilisateur pour ses repas : en planifier un (ADR 0029 décision 13, VOX-305),
 * changer son nombre de personnes, le retirer ou en baisser le nombre (décision 12, VOX-306). Adapté depuis le contexte `Repas` (ADR 0035).
 *
 * La liste active reçoit le repas et ses ingrédients dans les bonnes proportions ; sans liste active (aucune, ou envoyée au drive), une nouvelle
 * liste est créée. Une liste envoyée n'est jamais modifiée. Une recette n'est jamais deux fois dans une liste.
 */
interface MealPlanCommandPort
{
    /**
     * @param ?int $servings le nombre de personnes dit (1 à 99) ; `null` : celui du compte
     *
     * @return PlannedSpokenMeal `alreadyPlanned` vrai (rien n'est écrit, aucune liste n'est créée) quand la recette est déjà dans la liste active
     *
     * @throws \Repas\Voice\Domain\Exception\SpokenMealFailure la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient de la recette ne se
     *                                                          convertit pas dans son unité d'achat (rien n'est écrit)
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function plan(string $userId, string $recipeId, ?int $servings = null): PlannedSpokenMeal;

    /**
     * Fixe le NOUVEAU TOTAL de personnes d'un repas déjà dans la liste active : les lignes des ingrédients sont ajustées de la différence.
     *
     * @param int $servings le nouveau total, de 1 à 99
     *
     * @return ChangedMealServings `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou si la recette n'y est plus
     *
     * @throws \Repas\Voice\Domain\Exception\SpokenMealFailure la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient ne se convertit pas
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function changeServings(string $userId, string $recipeId, int $servings): ChangedMealServings;

    /**
     * Retire un repas de la liste active, ou en baisse le nombre de personnes : sans `$servings` le repas entier quitte la liste ; avec, ce nombre de
     * personnes est enlevé (4 moins 2 : 2), et le repas quitte la liste quand on en enlève autant ou plus (jamais un nombre de personnes nul ou
     * négatif). Une liste envoyée au drive n'est jamais modifiée.
     *
     * @param ?int $servings le nombre de personnes à enlever (1 à 99) ; `null` : tout le repas
     *
     * @return RemovedSpokenMeal `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou si la recette n'y est plus
     *
     * @throws \Repas\Voice\Domain\Exception\SpokenMealFailure la recette n'existe plus ou est à un autre utilisateur, ou un ingrédient ne se convertit pas
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99
     */
    public function removeMeal(string $userId, string $recipeId, ?int $servings = null): RemovedSpokenMeal;
}

<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;

/**
 * Port par lequel le contexte `Voice` planifie un repas (une recette de l'utilisateur) dans sa liste de courses (ADR 0029 décision 13, VOX-305).
 * Défini ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * La liste active reçoit le repas et ses ingrédients dans les bonnes proportions ; sans liste active (aucune, ou envoyée au drive), une nouvelle
 * liste est créée. Une liste envoyée n'est jamais modifiée. Une recette n'est jamais deux fois dans une liste.
 */
interface VoiceMealPlanner
{
    /**
     * @param ?int $servings le nombre de personnes dit (1 à 99) ; `null` : celui du compte
     *
     * @return PlannedSpokenMeal `alreadyPlanned` vrai (rien n'est écrit, aucune liste n'est créée) quand la recette est déjà dans la liste active
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException la recette n'existe pas ou est à un autre utilisateur, le nombre de personnes est hors de
     *                                                          1 à 99, ou un ingrédient de la recette ne se convertit pas dans son unité d'achat (rien n'est écrit)
     */
    public function plan(string $userId, string $recipeId, ?int $servings = null): PlannedSpokenMeal;

    /**
     * Fixe le NOUVEAU TOTAL de personnes d'un repas déjà dans la liste active : les lignes des ingrédients sont ajustées de la différence.
     *
     * @param int $servings le nouveau total, de 1 à 99
     *
     * @return ChangedMealServings `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou si la recette n'y est plus
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException le nombre de personnes est hors de 1 à 99, ou la recette est à un autre utilisateur
     */
    public function changeServings(string $userId, string $recipeId, int $servings): ChangedMealServings;
}

<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qu'a fait la planification d'un repas dans les listes de courses (ADR 0029 décision 13, VOX-305) : le repas a été planifié, ou il l'était déjà
 * (rien n'est écrit, et `servings` est alors le nombre de personnes pour lequel il est prévu).
 */
final readonly class MealPlanResult
{
    /**
     * @param list<string> $typedListNames les noms des listes d'un TYPE qui ont reçu un repas de la recette (ADR 0036, LST-008), dans l'ordre où la recette
     *                                     les atteint ; vide pour un compte sans type, ou quand tout est allé dans la liste principale
     * @param bool         $inMainList     la liste principale a reçu un repas de la recette (toujours vrai sans type, ou quand le repas était déjà planifié)
     */
    public function __construct(
        public string $recipeName,
        /** Le nombre de personnes du repas dans la liste : celui qui vient d'être planifié, ou celui qui y était déjà. */
        public int $servings,
        /** La recette était déjà dans la liste : rien n'a été écrit. */
        public bool $alreadyPlanned,
        /** Aucune liste active : une liste neuve a été commencée pour ce repas. */
        public bool $listCreated,
        /** Une liste envoyée au drive existait avant la liste neuve. */
        public bool $previousListSent,
        public array $typedListNames = [],
        public bool $inMainList = true,
    ) {
    }
}

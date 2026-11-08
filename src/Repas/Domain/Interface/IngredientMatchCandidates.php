<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\IngredientCandidate;

/**
 * Les ingrédients sur lesquels une copie de recette peut se brancher (ADR 0028 décision 8, `IngredientNameMatcher`) : ceux que
 * l'utilisateur VOIT, avec la même règle de visibilité que ses listes d'ingrédients (les siens, `IngredientVisibility`).
 * Lecture légère : des valeurs, une seule requête, aucun modèle hydraté.
 *
 * Un port d'écriture (lu par un `CommandHandler`) : il ne s'appelle donc pas `…Loader`, nom réservé aux ports de lecture des
 * `QueryHandler` (Deptrac `no-loader-in-commandhandler`).
 */
interface IngredientMatchCandidates
{
    /**
     * @return list<IngredientCandidate> triés par slug
     */
    public function visibleTo(string $userId): array;

    /**
     * Les ingrédients « de base » du catalogue du propriétaire `$ownerId` (créés par lui et portant le marqueur `is_base`, ADR 0028 amendement),
     * ceux que reçoit un compte à son activation : de quoi rapprocher le nom d'une ligne de recette Marmiton d'un ingrédient du catalogue
     * (ADR 0037 décision 6). Une seule requête, aucun modèle hydraté.
     *
     * @return list<IngredientCandidate> triés par slug
     */
    public function baseOf(string $ownerId): array;
}

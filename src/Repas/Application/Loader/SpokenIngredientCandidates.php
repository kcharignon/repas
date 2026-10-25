<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Port de lecture des ingrédients qu'une phrase prononcée peut désigner, avec l'habitude de l'utilisateur (`SpokenIngredientRanker`,
 * ADR 0029 décision 3, VOX-102).
 */
interface SpokenIngredientCandidates
{
    /**
     * Les ingrédients de l'utilisateur (ceux d'un autre n'existent pas pour lui, ADR 0028), chacun avec `uses12m` : le nombre de lignes de ses
     * listes de courses des douze derniers mois et de ses recettes où il figure (0 s'il n'a jamais servi). Un nombre de requêtes constant,
     * quel que soit le nombre d'ingrédients.
     *
     * @return list<SpokenCandidate>
     */
    public function forUser(string $userId): array;
}

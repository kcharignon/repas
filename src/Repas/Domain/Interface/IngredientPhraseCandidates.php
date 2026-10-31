<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Les ingrédients qu'une phrase prononcée peut désigner, avec l'habitude de l'utilisateur (`IngredientPhraseResolver`, ADR 0029 décision 3),
 * pour un appelant qui écrit et en tire un choix dans sa transaction. Lecture légère : des valeurs, aucun modèle hydraté.
 *
 * Un port d'écriture (lu par un `CommandHandler` ou un Open Host Service d'écriture) : il ne s'appelle donc pas `…Loader`, nom réservé aux
 * ports de lecture des `QueryHandler`. Le Loader `SpokenIngredientCandidates` rend la même chose (`SpokenIngredientPhraseReader`).
 */
interface IngredientPhraseCandidates
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

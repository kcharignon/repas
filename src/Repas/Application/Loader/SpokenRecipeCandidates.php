<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Port de lecture des recettes qu'une phrase prononcée peut désigner (`SpokenIngredientRanker`, ADR 0029 décision 13, VOX-305) : les recettes de
 * l'utilisateur, jamais celles d'un autre.
 */
interface SpokenRecipeCandidates
{
    /**
     * Les recettes dont l'utilisateur est l'auteur, une requête : `slug` porte l'IDENTIFIANT de la recette (le classeur rend ce qu'on lui donne),
     * `sluggedName` son nom normalisé, aucun rayon, aucune habitude.
     *
     * @return list<SpokenCandidate>
     */
    public function forUser(string $userId): array;
}

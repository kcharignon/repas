<?php

namespace Repas\Repas\Domain\Interface;

use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Les recettes qu'une phrase prononcée peut désigner (`RecipePhraseResolver`, ADR 0029 décision 13, VOX-305), pour un appelant qui écrit et en
 * tire un choix dans sa transaction : les recettes de l'utilisateur, jamais celles d'un autre.
 *
 * Un port d'écriture (lu par un Open Host Service d'écriture) : il ne s'appelle donc pas `…Loader`, nom réservé aux ports de lecture des
 * `QueryHandler`.
 */
interface RecipePhraseCandidates
{
    /**
     * Les recettes dont l'utilisateur est l'auteur, une requête : `slug` porte l'IDENTIFIANT de la recette (le classeur rend ce qu'on lui donne),
     * `sluggedName` son nom normalisé, aucun rayon, aucune habitude.
     *
     * @return list<SpokenCandidate>
     */
    public function forUser(string $userId): array;
}

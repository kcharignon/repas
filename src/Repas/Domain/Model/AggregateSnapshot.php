<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'état d'une recette ou d'une liste de courses d'un utilisateur, vu par les empreintes de la migration d'adoption : sa version, une
 * somme de contrôle de son contenu ((nom de l'ingrédient, rayon, quantité, unité) trié, jamais le slug), sa situation (statut et date
 * d'envoi pour une liste ; vide pour une recette), et si l'une de ses lignes référence un ingrédient surveillé (ceux qu'on va repointer).
 */
final readonly class AggregateSnapshot
{
    public function __construct(
        public int $version,
        public string $contentHash,
        public string $stateHash = '',
        public bool $referencesWatched = false,
        public bool $sent = false,
    ) {
    }
}

<?php

namespace Repas\Shared\Domain\Event;


/**
 * Un compte vient d'être supprimé par un administrateur : le contexte `Repas` efface tout ce qu'il possédait (listes de
 * courses, recettes, ingrédients personnalisés) comme s'il n'avait jamais existé (ADR 0021). L'événement ne porte que
 * l'identifiant, le contexte `Repas` n'a ni à lire le compte ni à importer son modèle.
 */
readonly class UserDeletedEvent
{

    public function __construct(
        public string $userId,
    ) {
    }
}

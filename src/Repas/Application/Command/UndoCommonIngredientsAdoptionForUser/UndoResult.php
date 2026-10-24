<?php

namespace Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser;


use Repas\Repas\Domain\Model\AdoptionRepointed;

/**
 * Ce que le retour arrière d'un lancement a fait pour un utilisateur : des nombres seulement.
 */
final readonly class UndoResult
{
    public function __construct(
        /** Adoptions défaites (alias effacés, lignes repointées vers le commun). */
        public int $undone = 0,
        /** Adoptions du lancement qu'on ne défait PAS parce que l'utilisateur a modifié la copie depuis (nom, rayon, unités, conversions). */
        public int $keptBecauseModified = 0,
        public AdoptionRepointed $repointed = new AdoptionRepointed(),
        public int $driveChoicesRepointed = 0,
    ) {
    }
}

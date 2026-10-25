<?php

namespace Repas\Voice\Domain\Interface;


/**
 * Ce que le contexte `Voice` retient d'un compte, vu de la suppression du compte (ADR 0021, ADR 0029 décision 9) : ses liaisons avec
 * Alexa, les jetons de ces liaisons et ses codes d'autorisation. VOX-202 ajoute les ports qui lisent et écrivent ces données
 * (liaisons, codes, jetons) ; celui-ci ne sait que les effacer toutes.
 */
interface UserVoiceDataRepository
{
    /**
     * Efface les liaisons du compte, leurs jetons (accès et rafraîchissement) et ses codes d'autorisation, usés ou non. Sans effet si le
     * compte n'a rien ; ne touche à aucun autre compte.
     */
    public function deleteByUser(string $userId): void;
}

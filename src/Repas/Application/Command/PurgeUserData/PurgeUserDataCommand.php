<?php

namespace Repas\Repas\Application\Command\PurgeUserData;


/**
 * Efface tout ce qu'un compte supprimé possédait dans `Repas` (ADR 0021). Émise par l'écoute de `UserDeletedEvent`,
 * jamais par un controller : le compte n'existe plus, aucune requête HTTP ne peut la demander.
 */
final readonly class PurgeUserDataCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}

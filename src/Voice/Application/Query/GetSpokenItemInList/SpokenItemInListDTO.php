<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


/**
 * La réponse parlée à « est-ce qu'il y a … dans la liste ? » : la phrase d'Alexa, précalculée (`VoiceReplies`), et si la séance reste ouverte.
 */
final readonly class SpokenItemInListDTO
{
    public function __construct(
        public string $speech,
        public bool $keepSessionOpen,
    ) {
    }
}

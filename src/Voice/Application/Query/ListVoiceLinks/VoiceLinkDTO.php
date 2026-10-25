<?php

namespace Repas\Voice\Application\Query\ListVoiceLinks;


/**
 * Une liaison de l'utilisateur avec un assistant vocal, telle que l'écran « Moi » la montre (VOX-402). Aucun jeton, aucun identifiant
 * Alexa : ni l'un ni l'autre ne sert à l'afficher ni à la retirer.
 */
final readonly class VoiceLinkDTO
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {
    }
}

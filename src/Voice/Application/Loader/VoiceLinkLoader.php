<?php

namespace Repas\Voice\Application\Loader;


use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;

interface VoiceLinkLoader
{
    /**
     * Les liaisons de l'utilisateur qui ne sont pas révoquées, la plus récente d'abord (à date égale, par identifiant).
     *
     * @return list<VoiceLinkDTO>
     */
    public function listOfUser(string $userId): array;
}

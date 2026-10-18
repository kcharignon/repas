<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;

interface DriveSettingsLoader
{
    /**
     * Les réglages de l'utilisateur pour l'enseigne ; sans réglage enregistré, `store` est `null`. `enabled` est l'état de
     * l'interrupteur du drive.
     */
    public function get(string $userId): DriveSettingsDTO;
}

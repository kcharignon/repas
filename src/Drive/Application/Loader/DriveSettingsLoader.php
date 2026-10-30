<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Domain\Model\Retailer;

interface DriveSettingsLoader
{
    /**
     * Les réglages de l'utilisateur pour l'enseigne (`retailer` et `store`) et, en plus, une entrée par enseigne connue
     * (`retailers`) ; sans réglage enregistré pour l'enseigne, `store` est `null`. `enabled` est l'état de l'interrupteur du drive.
     */
    public function get(string $userId, Retailer $retailer): DriveSettingsDTO;
}

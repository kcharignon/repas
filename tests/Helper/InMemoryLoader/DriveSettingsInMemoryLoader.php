<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;

/**
 * Double idiot : renvoie le DTO programmé quel que soit l'utilisateur demandé et enregistre ses appels.
 */
class DriveSettingsInMemoryLoader implements DriveSettingsLoader
{
    /** @var list<string> identifiants d'utilisateur reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DriveSettingsDTO $settings = new DriveSettingsDTO(true, 'leclerc', null),
    ) {
    }

    public function get(string $userId): DriveSettingsDTO
    {
        $this->getCalls[] = $userId;

        return $this->settings;
    }
}

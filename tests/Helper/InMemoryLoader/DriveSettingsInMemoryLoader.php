<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Double idiot : renvoie le DTO programmé quels que soient l'utilisateur et l'enseigne demandés et enregistre ses appels.
 */
class DriveSettingsInMemoryLoader implements DriveSettingsLoader
{
    /** @var list<array{string, Retailer}> utilisateur et enseigne reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DriveSettingsDTO $settings = new DriveSettingsDTO(true, 'leclerc', null, []),
    ) {
    }

    public function get(string $userId, Retailer $retailer): DriveSettingsDTO
    {
        $this->getCalls[] = [$userId, $retailer];

        return $this->settings;
    }
}

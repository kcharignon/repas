<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\DTO\DriveSettingsDTOBuilder;

/**
 * Double idiot : renvoie le DTO programmé quels que soient l'utilisateur et l'enseigne demandés et enregistre ses appels.
 */
class DriveSettingsInMemoryLoader implements DriveSettingsLoader
{
    /** @var list<array{string, ?Retailer}> utilisateur et enseigne reçus (`null` : celle de la sélection), dans l'ordre des appels */
    public array $getCalls = [];

    private DriveSettingsDTO $settings;

    public function __construct(?DriveSettingsDTO $settings = null)
    {
        $this->settings = $settings ?? new DriveSettingsDTOBuilder()->build();
    }

    public function get(string $userId, ?Retailer $retailer = null): DriveSettingsDTO
    {
        $this->getCalls[] = [$userId, $retailer];

        return $this->settings;
    }

    public function store(string $userId, Retailer $retailer): ?DriveStoreDTO
    {
        return $this->settings->store;
    }
}

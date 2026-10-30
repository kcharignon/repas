<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\RetailerSelectionDTO;
use Repas\Tests\Helper\Builder\Builder;

/**
 * Les réglages du drive d'une personne qui a un magasin chez la seule enseigne par défaut (la production d'aujourd'hui) : sélection
 * `AUTO` sur elle, ni drive habituel ni dernière enseigne. Valeurs factices uniquement. `unconfigured()` : rien d'enregistré (`NONE`).
 */
class DriveSettingsDTOBuilder implements Builder
{
    private bool $enabled = true;
    private bool $configured = true;
    private string $retailer = 'leclerc';
    private ?string $defaultRetailer = null;
    private ?string $lastUsedRetailer = null;

    public function disabled(): self
    {
        $this->enabled = false;

        return $this;
    }

    public function unconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    public function withRetailer(string $retailer): self
    {
        $this->retailer = $retailer;

        return $this;
    }

    public function withDefaultRetailer(?string $retailer): self
    {
        $this->defaultRetailer = $retailer;

        return $this;
    }

    public function withLastUsedRetailer(?string $retailer): self
    {
        $this->lastUsedRetailer = $retailer;

        return $this;
    }

    public function build(): DriveSettingsDTO
    {
        $store = $this->configured ? new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456') : null;

        return new DriveSettingsDTO(
            enabled: $this->enabled,
            retailer: $this->retailer,
            store: $store,
            retailers: [new DriveRetailerDTO($this->retailer, 'Leclerc', $this->configured, $this->defaultRetailer === $this->retailer, $store)],
            defaultRetailer: $this->defaultRetailer,
            lastUsedRetailer: $this->lastUsedRetailer,
            selection: new RetailerSelectionDTO(
                $this->configured ? 'AUTO' : 'NONE',
                $this->configured ? $this->retailer : null,
                $this->configured ? [$this->retailer] : [],
            ),
        );
    }
}

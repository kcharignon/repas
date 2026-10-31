<?php

namespace Repas\Drive\Application\Service;


use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Exception\DriveFeatureException;

/**
 * Le contrôle commun du drive habituel et de la dernière enseigne (DRV-703) : l'enseigne doit exister pour la personne, c'est-à-dire être
 * allumée (`DRIVE_RETAILERS`) ET configurée (un magasin enregistré, la présence d'un `DriveSettings` : jamais déduit d'un choix).
 */
readonly class ConfiguredRetailerGuard
{
    public function __construct(
        private DriveFeature $driveFeature,
        private DriveSettingsRepository $driveSettingsRepository,
    ) {
    }

    /**
     * @throws DriveFeatureException `retailerDisabled()` (404) : l'enseigne est éteinte, elle n'existe pas pour la personne (DRV-702)
     * @throws DriveException        `retailerNotConfigured()` (422) : la personne n'y a pas de magasin
     */
    public function assertAvailable(string $userId, Retailer $retailer): void
    {
        if (!$this->driveFeature->isRetailerEnabled($retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }
        if ($this->driveSettingsRepository->findOneByUser($userId, $retailer) === null) {
            throw DriveException::retailerNotConfigured();
        }
    }
}

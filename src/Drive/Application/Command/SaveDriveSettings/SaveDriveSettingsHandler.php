<?php

namespace Repas\Drive\Application\Command\SaveDriveSettings;


use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Store;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SaveDriveSettingsHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ClockInterface $clock,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * Enregistre le magasin de l'utilisateur pour l'enseigne ; un magasin déjà enregistré est remplacé.
     *
     * @throws DriveException magasin invalide (`DRIVE_STORE_INVALID`)
     */
    public function __invoke(SaveDriveSettingsCommand $command): void
    {
        // DRV-702 : une enseigne éteinte (`DRIVE_RETAILERS`) n'existe pas pour l'utilisateur, avant toute lecture ou écriture
        if (!$this->driveFeature->isRetailerEnabled($command->retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }
        $store = Store::create($command->retailer, $command->storeId, $command->storeLabel, $command->baseUrl);
        $now = $this->clock->now();

        $settings = $this->driveSettingsRepository->findOneByUser($command->userId, $command->retailer);
        $settings = $settings === null
            ? DriveSettings::create($command->userId, $command->retailer, $store, $now)
            : $settings->changeStore($store, $now);

        $this->driveSettingsRepository->save($settings);
    }
}

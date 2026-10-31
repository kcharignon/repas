<?php

namespace Repas\Drive\Application\Command\RemoveDriveSettings;


use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveDriveSettingsHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private RetailerPreferenceRepository $retailerPreferenceRepository,
        private ClockInterface $clock,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * Retire le drive d'une enseigne : son réglage (le magasin enregistré) disparaît, ses **choix et ses produits restent** (la personne les
     * retrouve si elle y revient). Le drive habituel et la dernière enseigne qui la désignaient sont effacés : « configurée » se juge sur
     * la présence du réglage, la préférence ne doit pas survivre à ce qu'elle désigne. Les listes `SENT` restent `SENT` (la liste ne porte
     * pas l'enseigne, ADR 0027 décision 9).
     *
     * @throws DriveFeatureException `retailerDisabled()` (404) : l'enseigne est éteinte (DRV-702)
     * @throws DriveException        `settingsNotFound()` (404) : la personne n'avait pas de magasin chez cette enseigne
     */
    public function __invoke(RemoveDriveSettingsCommand $command): void
    {
        if (!$this->driveFeature->isRetailerEnabled($command->retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }
        if (!$this->driveSettingsRepository->delete($command->userId, $command->retailer)) {
            throw DriveException::settingsNotFound();
        }

        $preference = $this->retailerPreferenceRepository->findOneByUser($command->userId);
        if ($preference?->refersTo($command->retailer)) {
            $this->retailerPreferenceRepository->save($preference->forget($command->retailer, $this->clock->now()));
        }
    }
}

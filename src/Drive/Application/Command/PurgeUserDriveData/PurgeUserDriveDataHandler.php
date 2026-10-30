<?php

namespace Repas\Drive\Application\Command\PurgeUserDriveData;


use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Efface les données du contexte `Drive` d'un compte supprimé (ADR 0021) : suppressions en masse, sans événement. Les produits
 * (`drive_product`) restent : ce sont des faits publics, sans propriétaire.
 */
#[AsMessageHandler]
readonly class PurgeUserDriveDataHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ChoiceRepository $choiceRepository,
        private RayonVoteRepository $rayonVoteRepository,
        private RetailerPreferenceRepository $retailerPreferenceRepository,
    ) {
    }

    public function __invoke(PurgeUserDriveDataCommand $command): void
    {
        $this->driveSettingsRepository->deleteByUser($command->userId);
        // Les lignes de produits d'abord : aucune clé étrangère ne les retrouverait une fois leurs préférences supprimées
        $this->choiceRepository->deleteProductsByUser($command->userId);
        $this->choiceRepository->deleteByUser($command->userId);
        $this->rayonVoteRepository->deleteByUser($command->userId);
        // Le drive habituel et la dernière enseigne (DRV-703)
        $this->retailerPreferenceRepository->deleteByUser($command->userId);
    }
}

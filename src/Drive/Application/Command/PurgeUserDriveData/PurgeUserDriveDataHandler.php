<?php

namespace Repas\Drive\Application\Command\PurgeUserDriveData;


use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Efface les données du contexte `Drive` d'un compte supprimé (ADR 0021) : suppressions en masse, sans événement.
 */
#[AsMessageHandler]
readonly class PurgeUserDriveDataHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
    ) {
    }

    public function __invoke(PurgeUserDriveDataCommand $command): void
    {
        $this->driveSettingsRepository->deleteByUser($command->userId);
    }
}

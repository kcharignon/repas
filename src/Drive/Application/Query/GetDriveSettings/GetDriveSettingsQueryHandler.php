<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Domain\Model\Retailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDriveSettingsQueryHandler
{
    public function __construct(
        private DriveSettingsLoader $driveSettingsLoader,
    ) {
    }

    public function __invoke(GetDriveSettingsQuery $query): DriveSettingsDTO
    {
        return $this->driveSettingsLoader->get($query->userId, $query->retailer ?? Retailer::default());
    }
}

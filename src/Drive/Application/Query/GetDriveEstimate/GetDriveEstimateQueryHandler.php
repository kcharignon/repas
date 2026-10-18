<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDriveEstimateQueryHandler
{
    public function __construct(
        private DriveEstimateLoader $driveEstimateLoader,
    ) {
    }

    public function __invoke(GetDriveEstimateQuery $query): DriveEstimateDTO
    {
        return $this->driveEstimateLoader->get($query->userId, $query->shoppingListId);
    }
}

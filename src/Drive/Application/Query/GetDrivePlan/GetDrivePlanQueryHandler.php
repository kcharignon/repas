<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Repas\Drive\Application\Loader\DrivePlanLoader;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDrivePlanQueryHandler
{
    public function __construct(
        private DrivePlanLoader $drivePlanLoader,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * @throws DriveFeatureException `retailerDisabled()` (404) si l'enseigne n'est pas allumée (`DRIVE_RETAILERS`, DRV-702)
     */
    public function __invoke(GetDrivePlanQuery $query): DrivePlanDTO
    {
        $retailer = $query->retailer ?? Retailer::default();
        if (!$this->driveFeature->isRetailerEnabled($retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }

        return $this->drivePlanLoader->get($query->userId, $query->shoppingListId, $retailer);
    }
}

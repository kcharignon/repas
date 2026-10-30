<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDriveEstimateQueryHandler
{
    public function __construct(
        private DriveEstimateLoader $driveEstimateLoader,
        private DriveFeature $driveFeature,
        private DriveSettingsLoader $driveSettingsLoader,
    ) {
    }

    /**
     * Sans enseigne dans la demande, c'est celle de la sélection (DRV-703) : le champ `retailer` des réglages, qui vaut l'enseigne par défaut
     * du registre quand la personne n'a rien configuré (l'estimation répond alors `409 DRIVE_NOT_CONFIGURED`, comme avant).
     *
     * @throws DriveFeatureException `retailerDisabled()` (404) si l'enseigne n'est pas allumée (`DRIVE_RETAILERS`, DRV-702)
     */
    public function __invoke(GetDriveEstimateQuery $query): DriveEstimateDTO
    {
        $retailer = $query->retailer ?? Retailer::from($this->driveSettingsLoader->get($query->userId)->retailer);
        if (!$this->driveFeature->isRetailerEnabled($retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }

        return $this->driveEstimateLoader->get($query->userId, $query->shoppingListId, $retailer);
    }
}

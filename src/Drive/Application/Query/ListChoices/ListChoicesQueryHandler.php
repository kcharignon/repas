<?php

namespace Repas\Drive\Application\Query\ListChoices;

use Repas\Drive\Application\Loader\ChoiceLoader;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListChoicesQueryHandler
{
    public function __construct(
        private ChoiceLoader $choiceLoader,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * @return list<ChoiceDTO>
     *
     * @throws DriveFeatureException `retailerDisabled()` (404) si l'enseigne n'est pas allumée (`DRIVE_RETAILERS`, DRV-702)
     */
    public function __invoke(ListChoicesQuery $query): array
    {
        if (!$this->driveFeature->isRetailerEnabled($query->retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }

        return $this->choiceLoader->list($query->userId, $query->retailer);
    }
}

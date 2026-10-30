<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDriveSettingsQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        /** `null` : l'enseigne en vigueur, celle de la sélection (DRV-703) */
        public ?Retailer $retailer = null,
    ) {
    }
}

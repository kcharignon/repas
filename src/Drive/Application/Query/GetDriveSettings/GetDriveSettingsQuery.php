<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDriveSettingsQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        /** `null` : l'enseigne par défaut (`Retailer::default()`) */
        public ?Retailer $retailer = null,
    ) {
    }
}

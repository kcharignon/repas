<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDrivePlanQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        /** `null` : l'enseigne de la sélection (DRV-703) */
        public ?Retailer $retailer = null,
    ) {
    }
}

<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDriveEstimateQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        /** `null` : l'enseigne par défaut (`Retailer::default()`) */
        public ?Retailer $retailer = null,
    ) {
    }
}

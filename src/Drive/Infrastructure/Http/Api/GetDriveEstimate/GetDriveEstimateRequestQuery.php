<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDriveEstimate;


use Repas\Drive\Domain\Model\Retailer;

final readonly class GetDriveEstimateRequestQuery
{
    public function __construct(
        public ?Retailer $retailer = null,
    ) {
    }
}

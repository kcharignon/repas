<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDrivePlan;


use Repas\Drive\Domain\Model\Retailer;

final readonly class GetDrivePlanRequestQuery
{
    public function __construct(
        public ?Retailer $retailer = null,
    ) {
    }
}

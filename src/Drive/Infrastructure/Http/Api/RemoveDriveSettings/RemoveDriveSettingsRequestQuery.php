<?php

namespace Repas\Drive\Infrastructure\Http\Api\RemoveDriveSettings;


use Repas\Drive\Domain\Model\Retailer;

final readonly class RemoveDriveSettingsRequestQuery
{
    public function __construct(
        public Retailer $retailer,
    ) {
    }
}

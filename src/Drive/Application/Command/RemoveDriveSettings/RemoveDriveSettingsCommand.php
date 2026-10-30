<?php

namespace Repas\Drive\Application\Command\RemoveDriveSettings;


use Repas\Drive\Domain\Model\Retailer;

readonly class RemoveDriveSettingsCommand
{
    public function __construct(
        public string $userId,
        public Retailer $retailer,
    ) {
    }
}

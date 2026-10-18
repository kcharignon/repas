<?php

namespace Repas\Drive\Application\Command\SaveDriveSettings;


use Repas\Drive\Domain\Model\Retailer;

readonly class SaveDriveSettingsCommand
{
    public function __construct(
        public string $userId,
        public Retailer $retailer,
        public string $storeId,
        public ?string $storeLabel,
        public string $baseUrl,
    ) {
    }
}

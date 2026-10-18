<?php

namespace Repas\Drive\Application\Command\PurgeUserDriveData;


readonly class PurgeUserDriveDataCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}

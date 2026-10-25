<?php

namespace Repas\Voice\Application\Command\PurgeUserVoiceData;


readonly class PurgeUserVoiceDataCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}

<?php

namespace Repas\User\Application\UpdateUserSettings;


readonly class UpdateUserSettingsCommand
{

    public function __construct(
        public string $userId,
        public ?string $newPassword,
        public string $defaultServing,
    ) {
    }
}

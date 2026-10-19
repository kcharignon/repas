<?php

namespace Repas\User\Application\UpdateUserAppVersion;


use Repas\User\Domain\Model\AppPlatform;

/**
 * L'application installée annonce sa version pour le compte connecté (DRV-637). `userId` vient du jeton, jamais du corps.
 */
readonly class UpdateUserAppVersionCommand
{
    public function __construct(
        public string $userId,
        public AppPlatform $platform,
        public string $version,
    ) {
    }
}

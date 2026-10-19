<?php

namespace Repas\Tests\Helper;


use Repas\User\Domain\Interface\AppReleaseProvider;
use Repas\User\Domain\Model\AppRelease;

/**
 * Versions publiées de l'application choisies par le test (DRV-637), à la place des variables d'environnement
 * `APP_LATEST_VERSION` et `APP_MIN_VERSION` (vides en test).
 */
final readonly class ControlledAppReleaseProvider implements AppReleaseProvider
{
    public function __construct(
        private ?string $latest = null,
        private ?string $minimum = null,
    ) {
    }

    public function current(): AppRelease
    {
        return AppRelease::fromSettings($this->latest, $this->minimum);
    }
}

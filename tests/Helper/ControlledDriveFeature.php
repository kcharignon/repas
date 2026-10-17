<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\DriveFeature;

/**
 * Interrupteur du drive à la main : `new ControlledDriveFeature(false)` pour essayer le cas coupé, sans toucher à l'environnement.
 */
final readonly class ControlledDriveFeature implements DriveFeature
{
    public function __construct(private bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

<?php

namespace Repas\Shared\Infrastructure\Feature;


use Repas\Shared\Application\Interface\DriveFeature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit l'interrupteur dans la variable d'environnement `DRIVE_ENABLED` (booléen).
 */
final readonly class EnvDriveFeature implements DriveFeature
{
    public function __construct(
        #[Autowire('%env(bool:DRIVE_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

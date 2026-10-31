<?php

namespace Repas\Tests\Helper;


use Repas\Voice\Domain\Interface\VoiceFeature;

/**
 * Interrupteur de la commande vocale à la main : `new ControlledVoiceFeature(false)` pour essayer le cas coupé, sans toucher à
 * l'environnement.
 */
final readonly class ControlledVoiceFeature implements VoiceFeature
{
    public function __construct(private bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

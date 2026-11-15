<?php

namespace Repas\Tests\Helper;


use Repas\Voice\Domain\Interface\VoiceTraceFeature;

/**
 * Interrupteur de la trace des échanges avec Alexa à la main : `new ControlledVoiceTraceFeature(true)` pour l'allumer, sans toucher à l'environnement
 * (il est coupé en test, `phpunit.dist.xml`).
 */
final readonly class ControlledVoiceTraceFeature implements VoiceTraceFeature
{
    public function __construct(private bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

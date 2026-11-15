<?php

namespace Repas\Voice\Infrastructure\Feature;


use Repas\Voice\Domain\Interface\VoiceTraceFeature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit l'interrupteur de la trace dans la variable d'environnement `ALEXA_TRACE_ENABLED` (booléen). Absente, vide ou invalide : coupé.
 */
final readonly class EnvVoiceTraceFeature implements VoiceTraceFeature
{
    public function __construct(
        #[Autowire('%env(bool:default::ALEXA_TRACE_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

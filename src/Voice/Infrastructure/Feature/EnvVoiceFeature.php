<?php

namespace Repas\Voice\Infrastructure\Feature;


use Repas\Voice\Domain\Interface\VoiceFeature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit l'interrupteur dans la variable d'environnement `ALEXA_ENABLED` (booléen). Absente, vide ou invalide : coupé.
 */
final readonly class EnvVoiceFeature implements VoiceFeature
{
    public function __construct(
        #[Autowire('%env(bool:default::ALEXA_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

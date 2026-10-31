<?php

namespace Repas\Voice\Infrastructure\Http;


use Repas\Voice\Domain\Interface\VoiceFeature;
use Repas\Voice\Domain\Exception\VoiceFeatureException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applique `#[RequiresVoice]` : quand l'interrupteur de la commande vocale est coupé, un controller marqué répond
 * `404 VOICE_DISABLED` (`ApiExceptionListener` met l'erreur en forme). Passe avant la résolution des arguments : un corps invalide ne
 * donne pas un `422` sur une fonction coupée, et rien du controller ne s'exécute.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class VoiceEnabledListener
{
    public function __construct(private VoiceFeature $voiceFeature)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ($event->getAttributes(RequiresVoice::class) === [] || $this->voiceFeature->isEnabled()) {
            return;
        }

        throw VoiceFeatureException::disabled();
    }
}

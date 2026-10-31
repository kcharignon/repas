<?php

namespace Repas\Drive\Infrastructure\Http;


use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applique `#[RequiresDrive]` : quand l'interrupteur du drive est coupé, un controller marqué répond `404 DRIVE_DISABLED`
 * (`ApiExceptionListener` met l'erreur en forme). Passe après la sécurité (un appel anonyme reste un `401`) et avant la
 * résolution des arguments (un corps invalide ne donne pas un `422` sur une fonction coupée).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class DriveEnabledListener
{
    public function __construct(private DriveFeature $driveFeature)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ($event->getAttributes(RequiresDrive::class) === [] || $this->driveFeature->isEnabled()) {
            return;
        }

        throw DriveFeatureException::disabled();
    }
}

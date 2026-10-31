<?php

namespace Repas\Repas\Infrastructure\Http;

use Repas\Repas\Application\Port\DriveSwitchQueryPort;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applique `#[RequiresDriveSending]` : quand l'interrupteur du drive est coupé, la route d'envoi d'une liste répond
 * `404 DRIVE_DISABLED` (`ApiExceptionListener` met l'erreur en forme). Passe après la sécurité (un appel anonyme reste un `401`) et avant la
 * résolution des arguments (un corps invalide ne donne pas un `422` sur une fonction coupée).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class DriveSendingEnabledListener
{
    public function __construct(private DriveSwitchQueryPort $driveSwitch)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ($event->getAttributes(RequiresDriveSending::class) === [] || $this->driveSwitch->isEnabled()) {
            return;
        }

        throw ShoppingListException::driveDisabled();
    }
}

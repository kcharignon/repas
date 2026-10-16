<?php

namespace Repas\Repas\Infrastructure\EventListener;

use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Un compte est supprimé : ses données du contexte `Repas` partent avec lui (ADR 0021). L'écoute est synchrone, donc dans
 * la transaction de `DeleteUser` : si la purge échoue, le compte n'est pas supprimé.
 */
#[AsEventListener]
readonly class PurgeUserDataOnUserDeletedListener
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(UserDeletedEvent $event): void
    {
        $this->commandBus->dispatch(new PurgeUserDataCommand($event->userId));
    }
}

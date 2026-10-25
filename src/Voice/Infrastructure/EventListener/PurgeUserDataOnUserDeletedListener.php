<?php

namespace Repas\Voice\Infrastructure\EventListener;

use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Voice\Application\Command\PurgeUserVoiceData\PurgeUserVoiceDataCommand;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Un compte est supprimé : ses données du contexte `Voice` partent avec lui (ADR 0021, ADR 0029 décision 9). L'écoute est synchrone,
 * donc dans la transaction de `DeleteUser` : si la purge échoue, le compte n'est pas supprimé.
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
        $this->commandBus->dispatch(new PurgeUserVoiceDataCommand($event->userId));
    }
}

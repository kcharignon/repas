<?php

namespace Repas\Shared\Application\Service;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class CommandBus implements CommandBusInterface
{

    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @throws ExceptionInterface
     */
    public function dispatch(object $command): mixed
    {
        // Utilise le bus Symfony pour dispatcher la requête
        $envelope = $this->messageBus->dispatch($command);

        // Extrait le résultat du HandledStamp
        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        if (!$handledStamp) {
            throw new \RuntimeException('The query was not handled correctly.');
        }

        return $handledStamp->getResult();
    }

}

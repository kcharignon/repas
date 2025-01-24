<?php

namespace Repas\Shared\Application\Service;


use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class QueryBus implements QueryBusInterface
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function ask(object $query): mixed
    {
        // Utilise le bus Symfony pour dispatcher la requête
        $envelope = $this->messageBus->dispatch($query);

        // Extrait le résultat du HandledStamp
        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        if (!$handledStamp) {
            throw new \RuntimeException('The query was not handled correctly.');
        }

        return $handledStamp->getResult();
    }
}

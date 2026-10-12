<?php

namespace Repas\Shared\Application\Service;


use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Application\Interface\QueryInterface;
use Repas\Shared\Domain\Exception\DomainException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class QueryBus implements QueryBusInterface
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @throws ExceptionInterface
     * @throws DomainException
     */
    public function ask(QueryInterface $query): mixed
    {
        // Utilise le bus Symfony pour dispatcher la requête
        try {
            $envelope = $this->messageBus->dispatch($query);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof DomainException) {
                throw $previous;
            }

            throw $e;
        }

        // Extrait le résultat du HandledStamp
        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        if (!$handledStamp) {
            throw new \RuntimeException('The query was not handled correctly.');
        }

        return $handledStamp->getResult();
    }
}

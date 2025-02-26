<?php

namespace Repas\Shared\Application\Service;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Exception\DomainException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

class CommandBus implements CommandBusInterface
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
    public function dispatch(object $command): mixed
    {
        // Utilise le bus Symfony pour dispatcher la requête
        try {
            $envelope = $this->messageBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof DomainException) {
                throw $previous;
            } else {
                throw $e;
            }
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

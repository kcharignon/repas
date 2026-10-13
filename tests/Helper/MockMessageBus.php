<?php

namespace Repas\Tests\Helper;


use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Double du bus Messenger sous CommandBus / QueryBus : enregistre les messages reçus et simule ce que le vrai bus
 * renvoie (un HandledStamp portant le résultat, une HandlerFailedException, ou un message que personne n'a traité).
 */
class MockMessageBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    private array $envelopes = [];

    /** @var array<class-string, mixed> */
    private array $results = [];

    /** @var array<class-string, list<Throwable>> */
    private array $handlerFailures = [];

    /** @var array<class-string, true> */
    private array $unhandled = [];

    /**
     * @param class-string $messageClass
     */
    public function willReturn(string $messageClass, mixed $result): self
    {
        $this->results[$messageClass] = $result;

        return $this;
    }

    /**
     * Le handler lève ces exceptions : le vrai bus les enveloppe dans une HandlerFailedException.
     *
     * @param class-string $messageClass
     */
    public function willFailWith(string $messageClass, Throwable ...$handlerExceptions): self
    {
        $this->handlerFailures[$messageClass] = $handlerExceptions;

        return $this;
    }

    /**
     * Aucun handler n'a traité le message : l'enveloppe revient sans HandledStamp.
     *
     * @param class-string $messageClass
     */
    public function willLeaveUnhandled(string $messageClass): self
    {
        $this->unhandled[$messageClass] = true;

        return $this;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = new Envelope($message, $stamps);
        $this->envelopes[] = $envelope;

        if (isset($this->handlerFailures[$message::class])) {
            throw new HandlerFailedException($envelope, $this->handlerFailures[$message::class]);
        }

        if (isset($this->unhandled[$message::class])) {
            return $envelope;
        }

        return $envelope->with(new HandledStamp($this->results[$message::class] ?? null, 'mock-handler'));
    }

    /**
     * @return list<Envelope>
     */
    public function getEnvelopes(): array
    {
        return $this->envelopes;
    }

    public function getLastMessage(): ?object
    {
        $last = $this->envelopes[array_key_last($this->envelopes)] ?? null;

        return $last?->getMessage();
    }
}

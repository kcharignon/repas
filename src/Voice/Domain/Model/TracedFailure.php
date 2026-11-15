<?php

namespace Repas\Voice\Domain\Model;


use Throwable;

/**
 * Ce que la trace garde d'une exception (ADR 0029 décision 14) : sa classe, son endroit (fichier:ligne, sans le dossier) et son message tronqué,
 * puis les mêmes trois choses pour sa cause (`getPrevious()`) quand le bus l'a enveloppée dans un `HandlerFailedException`. Le message peut citer
 * ce qui a été dit : il n'est gardé que dans la table `voice_trace` (sous interrupteur, bornée, purgée avec le compte), jamais dans le journal.
 */
final readonly class TracedFailure
{
    public function __construct(
        public string $class,
        public string $location,
        public string $message,
        public ?string $previousClass = null,
        public ?string $previousLocation = null,
        public ?string $previousMessage = null,
    ) {
    }

    public static function fromThrowable(Throwable $exception): self
    {
        $previous = $exception->getPrevious();

        return new self(
            $exception::class,
            self::locationOf($exception),
            VoiceTrace::bounded($exception->getMessage()),
            $previous === null ? null : $previous::class,
            $previous === null ? null : self::locationOf($previous),
            $previous === null ? null : VoiceTrace::bounded($previous->getMessage()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = ['class' => $this->class, 'location' => $this->location, 'message' => $this->message];
        if ($this->previousClass !== null) {
            $data['previousClass'] = $this->previousClass;
            $data['previousLocation'] = (string) $this->previousLocation;
            $data['previousMessage'] = (string) $this->previousMessage;
        }

        return $data;
    }

    private static function locationOf(Throwable $exception): string
    {
        return basename($exception->getFile()) . ':' . $exception->getLine();
    }
}

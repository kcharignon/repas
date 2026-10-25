<?php

namespace Repas\Tests\Helper;


use Psr\Log\AbstractLogger;

/**
 * Un journal qui garde ce qu'on lui écrit AVEC son contexte, pour prouver qu'un avertissement a été journalisé et qu'aucun secret n'y est
 * passé (`SpyLogger` ne garde que le niveau et le message).
 */
final class ContextSpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Tout ce qui a été écrit, en un texte, pour y chercher une valeur qui ne doit pas y être.
     */
    public function everything(): string
    {
        return (string) json_encode($this->records, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

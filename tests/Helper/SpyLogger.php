<?php

namespace Repas\Tests\Helper;


use Psr\Log\AbstractLogger;

/**
 * Un journal qui garde ce qu'on lui écrit, pour prouver qu'un avertissement a été journalisé.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * @return list<string> les messages d'un niveau
     */
    public function messages(string $level): array
    {
        return array_values(array_map(static fn(array $record): string => $record['message'], array_filter($this->records, static fn(array $record): bool => $record['level'] === $level)));
    }
}

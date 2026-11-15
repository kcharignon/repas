<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\VoiceTrace;

/**
 * La trace des échanges avec Alexa en mémoire : mêmes règles que la base (numéros croissants, seuls les `VoiceTrace::RETENTION` plus récents sont
 * gardés), pour tester le handler sans base.
 */
class VoiceTraceInMemoryRepository implements VoiceTraceRepository
{
    /** @var array<int, VoiceTrace> les échanges gardés, par numéro */
    private array $traces = [];

    private int $lastId = 0;

    public function add(VoiceTrace $trace): void
    {
        $this->traces[++$this->lastId] = $trace;

        // Seuls les plus récents : on retire ce qui dépasse, le plus ancien d'abord
        while (count($this->traces) > VoiceTrace::RETENTION) {
            unset($this->traces[array_key_first($this->traces)]);
        }
    }

    /**
     * @return array<int, VoiceTrace> les échanges gardés, par numéro croissant
     */
    public function all(): array
    {
        return $this->traces;
    }

    public function last(): ?VoiceTrace
    {
        return $this->traces === [] ? null : $this->traces[array_key_last($this->traces)];
    }
}

<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use DateTimeImmutable;
use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Repas\Voice\Application\Query\GetVoiceTrace\VoiceTraceDetailDTO;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;

/**
 * Double idiot : renvoie les DTO programmés et enregistre ce qu'on lui a demandé.
 */
class VoiceTraceInMemoryLoader implements VoiceTraceLoader
{
    /** @var list<array{limit: int, intent: ?string, failedOnly: bool, since: ?DateTimeImmutable}> */
    public array $askedLists = [];

    /** @var list<int> */
    public array $askedIds = [];

    /**
     * @param list<VoiceTraceSummaryDTO> $traces
     */
    public function __construct(
        private readonly array $traces = [],
        private readonly ?VoiceTraceDetailDTO $detail = null,
    ) {
    }

    public function recent(int $limit, ?string $intent, bool $failedOnly, ?DateTimeImmutable $since): array
    {
        $this->askedLists[] = ['limit' => $limit, 'intent' => $intent, 'failedOnly' => $failedOnly, 'since' => $since];

        return $this->traces;
    }

    public function one(int $id): ?VoiceTraceDetailDTO
    {
        $this->askedIds[] = $id;

        return $this->detail;
    }
}

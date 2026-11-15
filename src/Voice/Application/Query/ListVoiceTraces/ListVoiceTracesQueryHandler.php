<?php

namespace Repas\Voice\Application\Query\ListVoiceTraces;


use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListVoiceTracesQueryHandler
{
    public function __construct(
        private VoiceTraceLoader $voiceTraceLoader,
    ) {
    }

    /**
     * @return list<VoiceTraceSummaryDTO>
     */
    public function __invoke(ListVoiceTracesQuery $query): array
    {
        return $this->voiceTraceLoader->recent($query->limit, $query->intent, $query->failedOnly, $query->since);
    }
}

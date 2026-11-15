<?php

namespace Repas\Voice\Application\Query\GetVoiceTrace;


use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetVoiceTraceQueryHandler
{
    public function __construct(
        private VoiceTraceLoader $voiceTraceLoader,
    ) {
    }

    public function __invoke(GetVoiceTraceQuery $query): ?VoiceTraceDetailDTO
    {
        return $this->voiceTraceLoader->one($query->id);
    }
}

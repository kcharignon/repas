<?php

namespace Repas\Voice\Application\Query\ListVoiceLinks;


use Repas\Voice\Application\Loader\VoiceLinkLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListVoiceLinksQueryHandler
{
    public function __construct(
        private VoiceLinkLoader $voiceLinkLoader,
    ) {
    }

    /**
     * @return list<VoiceLinkDTO>
     */
    public function __invoke(ListVoiceLinksQuery $query): array
    {
        return $this->voiceLinkLoader->listOfUser($query->userId);
    }
}

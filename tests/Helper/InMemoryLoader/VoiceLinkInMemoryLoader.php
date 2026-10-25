<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Voice\Application\Loader\VoiceLinkLoader;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;

/**
 * Double idiot : renvoie les DTO programmés et enregistre les utilisateurs demandés.
 */
class VoiceLinkInMemoryLoader implements VoiceLinkLoader
{
    /** @var list<string> */
    public array $askedUserIds = [];

    /**
     * @param list<VoiceLinkDTO> $links
     */
    public function __construct(private readonly array $links = [])
    {
    }

    public function listOfUser(string $userId): array
    {
        $this->askedUserIds[] = $userId;

        return $this->links;
    }
}

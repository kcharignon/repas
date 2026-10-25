<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

class VoiceLinkInMemoryRepository implements VoiceLinkRepository
{
    /** @var array<string, VoiceLink> */
    private array $links = [];

    public function findOneById(string $id): ?VoiceLink
    {
        return isset($this->links[$id]) ? clone $this->links[$id] : null;
    }

    public function findAwaitingAlexaOfUser(string $userId): ?VoiceLink
    {
        $candidates = array_filter(
            $this->links,
            static fn(VoiceLink $link): bool => $link->getUserId() === $userId && $link->getAlexaUserId() === null && !$link->isRevoked(),
        );
        usort($candidates, static fn(VoiceLink $a, VoiceLink $b): int => [$b->getCreatedAt(), $a->getId()] <=> [$a->getCreatedAt(), $b->getId()]);

        return $candidates === [] ? null : clone $candidates[0];
    }

    public function save(VoiceLink $link): void
    {
        $this->links[$link->getId()] = clone $link;
    }

    /**
     * @return list<VoiceLink>
     */
    public function all(): array
    {
        return array_values($this->links);
    }
}

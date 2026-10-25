<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
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

    public function recordUse(string $linkId, DateTimeImmutable $at): void
    {
        $link = $this->links[$linkId] ?? null;
        if ($link === null || $link->isRevoked() || ($link->getLastUsedAt() !== null && $link->getLastUsedAt() >= $at)) {
            return;
        }

        $this->links[$linkId] = $this->withFields($link, lastUsedAt: $at);
    }

    public function identifyAlexaUser(string $linkId, string $alexaUserId): bool
    {
        $link = $this->links[$linkId] ?? null;
        if ($link === null || $link->isRevoked() || $link->getAlexaUserId() !== null) {
            return false;
        }

        $this->links[$linkId] = $this->withFields($link, alexaUserId: $alexaUserId);

        return true;
    }

    public function findOthersOfAlexaUser(string $userId, string $alexaUserId, string $exceptLinkId): array
    {
        $others = array_filter(
            $this->links,
            static fn(VoiceLink $link): bool => $link->getUserId() === $userId
                && $link->getAlexaUserId() === $alexaUserId
                && $link->getId() !== $exceptLinkId
                && !$link->isRevoked(),
        );
        usort($others, static fn(VoiceLink $a, VoiceLink $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

        return array_map(static fn(VoiceLink $link): VoiceLink => clone $link, $others);
    }

    private function withFields(VoiceLink $link, ?string $alexaUserId = null, ?DateTimeImmutable $lastUsedAt = null): VoiceLink
    {
        return VoiceLink::load([
            'id' => $link->getId(),
            'user_id' => $link->getUserId(),
            'alexa_user_id' => $alexaUserId ?? $link->getAlexaUserId(),
            'created_at' => $link->getCreatedAt(),
            'last_used_at' => $lastUsedAt ?? $link->getLastUsedAt(),
            'revoked_at' => $link->getRevokedAt(),
        ]);
    }

    /**
     * @return list<VoiceLink>
     */
    public function all(): array
    {
        return array_values($this->links);
    }
}

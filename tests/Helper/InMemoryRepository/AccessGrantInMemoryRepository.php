<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Model\AccessGrant;

class AccessGrantInMemoryRepository implements AccessGrantRepository
{
    /** @var array<string, AccessGrant> */
    private array $grants = [];

    public function add(AccessGrant $grant): void
    {
        $this->grants[$grant->getId()] = clone $grant;
    }

    public function findOneByRefreshHash(string $refreshHash): ?AccessGrant
    {
        foreach ($this->grants as $grant) {
            if ($grant->getRefreshHash() === $refreshHash) {
                return clone $grant;
            }
        }

        return null;
    }

    public function consumeRefresh(string $refreshHash, DateTimeImmutable $now): bool
    {
        foreach ($this->grants as $id => $grant) {
            if ($grant->getRefreshHash() !== $refreshHash) {
                continue;
            }
            if ($grant->isRefreshUsed()) {
                return false;
            }

            $this->grants[$id] = AccessGrant::load([
                'access_hash' => $grant->getId(),
                'refresh_hash' => $grant->getRefreshHash(),
                'link_id' => $grant->getLinkId(),
                'user_id' => $grant->getUserId(),
                'expires_at' => $grant->getExpiresAt(),
                'refresh_expires_at' => $grant->getRefreshExpiresAt(),
                'used_at' => $now,
            ]);

            return true;
        }

        return false;
    }

    public function deleteByLink(string $linkId): void
    {
        $this->grants = array_filter($this->grants, static fn(AccessGrant $grant): bool => $grant->getLinkId() !== $linkId);
    }

    public function deleteExpiredOfLink(string $linkId, DateTimeImmutable $now): void
    {
        $this->grants = array_filter(
            $this->grants,
            static fn(AccessGrant $grant): bool => $grant->getLinkId() !== $linkId || !$grant->isRefreshExpired($now),
        );
    }

    /**
     * @return list<AccessGrant>
     */
    public function all(): array
    {
        return array_values($this->grants);
    }
}

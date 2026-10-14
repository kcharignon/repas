<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Model\RefreshToken;

class RefreshTokenInMemoryRepository implements RefreshTokenRepository
{
    /** @var array<string, RefreshToken> */
    private array $tokens = [];

    /**
     * @param list<RefreshToken> $tokens
     */
    public function __construct(array $tokens = [])
    {
        foreach ($tokens as $token) {
            $this->save($token);
        }
    }

    public function findOneByTokenHash(string $tokenHash): ?RefreshToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getTokenHash() === $tokenHash) {
                return clone $token;
            }
        }

        return null;
    }

    public function save(RefreshToken $refreshToken): void
    {
        $this->tokens[$refreshToken->getId()] = clone $refreshToken;
    }

    public function deleteFamily(string $familyId): void
    {
        $this->tokens = array_filter($this->tokens, static fn(RefreshToken $token): bool => $token->getFamilyId() !== $familyId);
    }

    public function deleteOldestFamiliesOfUser(string $userId, int $keep): void
    {
        $latestByFamily = [];
        foreach ($this->tokens as $token) {
            if ($token->getUserId() === $userId) {
                $latestByFamily[$token->getFamilyId()] = max($latestByFamily[$token->getFamilyId()] ?? 0, $token->getIssuedAt()->getTimestamp());
            }
        }
        arsort($latestByFamily);

        foreach (array_slice(array_keys($latestByFamily), $keep) as $familyId) {
            $this->deleteFamily($familyId);
        }
    }

    public function deleteExpired(DateTimeImmutable $now): int
    {
        $before = count($this->tokens);
        $this->tokens = array_filter($this->tokens, static fn(RefreshToken $token): bool => !$token->isExpired($now));

        return $before - count($this->tokens);
    }

    /**
     * @return list<RefreshToken>
     */
    public function all(): array
    {
        return array_values($this->tokens);
    }
}

<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Model\PasswordResetToken;

class PasswordResetTokenInMemoryRepository implements PasswordResetTokenRepository
{
    /** @var array<string, PasswordResetToken> */
    private array $tokens = [];

    /**
     * @param list<PasswordResetToken> $tokens
     */
    public function __construct(array $tokens = [])
    {
        foreach ($tokens as $token) {
            $this->add($token);
        }
    }

    public function findOneByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getTokenHash() === $tokenHash) {
                return $token;
            }
        }

        return null;
    }

    public function findOneByUserId(string $userId): ?PasswordResetToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getUserId() === $userId) {
                return $token;
            }
        }

        return null;
    }

    public function add(PasswordResetToken $token): void
    {
        $this->tokens[$token->getId()] = $token;
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->tokens = array_filter($this->tokens, static fn(PasswordResetToken $token): bool => $token->getUserId() !== $userId);
    }

    /**
     * @return list<PasswordResetToken>
     */
    public function all(): array
    {
        return array_values($this->tokens);
    }
}

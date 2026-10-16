<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Model\EmailVerificationToken;

class EmailVerificationTokenInMemoryRepository implements EmailVerificationTokenRepository
{
    /** @var array<string, EmailVerificationToken> */
    private array $tokens = [];

    /**
     * @param list<EmailVerificationToken> $tokens
     */
    public function __construct(array $tokens = [])
    {
        foreach ($tokens as $token) {
            $this->add($token);
        }
    }

    public function findOneByTokenHash(string $tokenHash): ?EmailVerificationToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getTokenHash() === $tokenHash) {
                return $token;
            }
        }

        return null;
    }

    public function findOneByUserId(string $userId): ?EmailVerificationToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getUserId() === $userId) {
                return $token;
            }
        }

        return null;
    }

    public function add(EmailVerificationToken $token): void
    {
        $this->tokens[$token->getId()] = $token;
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->tokens = array_filter($this->tokens, static fn(EmailVerificationToken $token): bool => $token->getUserId() !== $userId);
    }

    /**
     * @return list<EmailVerificationToken>
     */
    public function all(): array
    {
        return array_values($this->tokens);
    }
}

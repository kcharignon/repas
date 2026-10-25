<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Model\AuthorizationCode;

class AuthorizationCodeInMemoryRepository implements AuthorizationCodeRepository
{
    /** @var array<string, AuthorizationCode> */
    private array $codes = [];

    public function add(AuthorizationCode $code): void
    {
        $this->codes[$code->getId()] = clone $code;
    }

    public function findOneByHash(string $codeHash): ?AuthorizationCode
    {
        return isset($this->codes[$codeHash]) ? clone $this->codes[$codeHash] : null;
    }

    public function consume(string $codeHash, DateTimeImmutable $now, ?string $linkId): bool
    {
        $code = $this->codes[$codeHash] ?? null;
        if ($code === null || $code->isUsed()) {
            return false;
        }

        $this->codes[$codeHash] = AuthorizationCode::load([
            'code_hash' => $code->getId(),
            'user_id' => $code->getUserId(),
            'client_id' => $code->getClientId(),
            'redirect_uri' => $code->getRedirectUri(),
            'code_challenge' => $code->getCodeChallenge(),
            'expires_at' => $code->getExpiresAt(),
            'used_at' => $now,
            'link_id' => $linkId,
        ]);

        return true;
    }

    public function deleteExpiredOfUser(string $userId, DateTimeImmutable $now): void
    {
        $this->codes = array_filter(
            $this->codes,
            static fn(AuthorizationCode $code): bool => $code->getUserId() !== $userId || !$code->isExpired($now),
        );
    }

    /**
     * @return list<AuthorizationCode>
     */
    public function all(): array
    {
        return array_values($this->codes);
    }
}

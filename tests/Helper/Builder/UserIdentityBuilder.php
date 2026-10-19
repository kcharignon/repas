<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserIdentity;

/**
 * Sujets factices uniquement (`sujet-test-…`) : jamais le `sub` d'un vrai compte Google.
 */
class UserIdentityBuilder implements Builder
{
    private string $id;
    private string $userId;
    private IdentityProvider $provider;
    private string $subject;
    private DateTimeImmutable $createdAt;

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->userId ??= UuidGenerator::new();
        $this->provider ??= IdentityProvider::GOOGLE;
        $this->subject ??= 'sujet-test-' . $this->id;
        $this->createdAt ??= new DateTimeImmutable('2026-10-20 09:00:00');
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function withUser(User $user): self
    {
        $this->userId = $user->getId();
        return $this;
    }

    public function withSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function withCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function build(): UserIdentity
    {
        $this->initialize();
        return UserIdentity::load([
            'id' => $this->id,
            'user_id' => $this->userId,
            'provider' => $this->provider,
            'subject' => $this->subject,
            'created_at' => $this->createdAt,
        ]);
    }
}

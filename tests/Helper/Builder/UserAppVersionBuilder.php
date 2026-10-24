<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserAppVersion;

class UserAppVersionBuilder implements Builder
{
    private string $userId;
    private AppPlatform $platform;
    private string $version;
    private DateTimeImmutable $seenAt;

    private function initialize(): void
    {
        $this->userId ??= UuidGenerator::new();
        $this->platform ??= AppPlatform::ANDROID;
        $this->version ??= '0.1.8';
        $this->seenAt ??= new DateTimeImmutable('2026-10-20 09:00:00');
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

    public function withVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function withSeenAt(DateTimeImmutable $seenAt): self
    {
        $this->seenAt = $seenAt;
        return $this;
    }

    public function build(): UserAppVersion
    {
        $this->initialize();
        return UserAppVersion::load([
            'user_id' => $this->userId,
            'platform' => $this->platform,
            'version' => AppVersion::fromString($this->version),
            'seen_at' => $this->seenAt,
        ]);
    }
}

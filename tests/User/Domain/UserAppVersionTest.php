<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Domain\Model\UserAppVersion;

class UserAppVersionTest extends TestCase
{
    public function testASeenVersionKeepsOnlyTheAccountThePlatformTheVersionAndTheTime(): void
    {
        // Given
        $seenAt = new DateTimeImmutable('2026-10-20 10:15:00');

        // When
        $appVersion = UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.8'), $seenAt);

        // Then : rien d'autre (vie privée : ni appareil, ni modèle de téléphone)
        $this->assertSame('user-id', $appVersion->getUserId());
        $this->assertSame('0.1.8', $appVersion->getVersion()->toString());
        $this->assertSame($seenAt, $appVersion->getSeenAt());
        $this->assertSame(['user_id', 'platform', 'version', 'seen_at'], array_keys($appVersion->toArray()));
    }

    public function testTheKeyIsTheAccountAndThePlatform(): void
    {
        // Given
        $first = UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.8'), new DateTimeImmutable('2026-10-20 10:00:00'));
        $later = UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.10'), new DateTimeImmutable('2026-10-21 10:00:00'));
        $other = UserAppVersion::seen('other-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.8'), new DateTimeImmutable('2026-10-20 10:00:00'));

        // Then
        $this->assertSame('user-id|android', $first->getId());
        $this->assertTrue($first->isEqual($later));
        $this->assertFalse($first->isEqual($other));
    }
}

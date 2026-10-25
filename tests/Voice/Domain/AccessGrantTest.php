<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\AccessGrant;

class AccessGrantTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
    }

    public function testTheAccessTokenLastsAnHourAndTheRefreshTokenNinetyDays(): void
    {
        // When
        $grant = AccessGrant::issue('access-hash', 'refresh-hash', 'link-1', 'user-1', $this->now);

        // Then
        $this->assertSame('2026-10-25 13:00:00', $grant->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-23 12:00:00', $grant->getRefreshExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertSame('access-hash', $grant->getId());
        $this->assertSame(['refresh-hash', 'link-1', 'user-1'], [$grant->getRefreshHash(), $grant->getLinkId(), $grant->getUserId()]);
        $this->assertFalse($grant->isRefreshUsed());
    }

    public function testTheRefreshTokenExpiresAtTheSecond(): void
    {
        $grant = AccessGrant::issue('a', 'r', 'link-1', 'user-1', $this->now);

        $this->assertFalse($grant->isRefreshExpired($grant->getRefreshExpiresAt()->modify('-1 second')));
        $this->assertTrue($grant->isRefreshExpired($grant->getRefreshExpiresAt()));
    }

    public function testRotatingGivesANewPairForTheSameLinkAndAccountWithFreshDurations(): void
    {
        // Given
        $grant = AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now);
        $later = $this->now->modify('+50 minutes');

        // When
        $next = $grant->rotate('access-2', 'refresh-2', $later);

        // Then : la liaison et le compte ne changent pas, les durées repartent de maintenant, la paire d'origine n'est pas touchée
        $this->assertSame(['access-2', 'refresh-2', 'link-1', 'user-1'], [$next->getId(), $next->getRefreshHash(), $next->getLinkId(), $next->getUserId()]);
        $this->assertSame('2026-10-25 13:50:00', $next->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-23 12:50:00', $next->getRefreshExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($next->isRefreshUsed());
        $this->assertSame('2026-10-25 13:00:00', $grant->getExpiresAt()->format('Y-m-d H:i:s'));
    }
}

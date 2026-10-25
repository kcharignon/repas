<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Shared\Application\Interface\VoiceLinkOwnershipLoader;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

class VoiceLinkOwnershipLoaderTest extends DatabaseTestCase
{
    public function testTheOwnerOfALinkIsItsUser(): void
    {
        // Given
        $links = static::getContainer()->get(VoiceLinkRepository::class);
        $links->save(VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00')));
        $links->save(VoiceLink::create('link-revoked', 'user-2', new DateTimeImmutable('2026-10-25 10:00:00'))->revoke(new DateTimeImmutable('2026-10-25 11:00:00')));

        // When
        $loader = static::getContainer()->get(VoiceLinkOwnershipLoader::class);

        // Then : une liaison retirée a toujours son propriétaire (retirer deux fois ne change rien)
        $this->assertSame('user-1', $loader->voiceLinkOwnerId('link-1'));
        $this->assertSame('user-2', $loader->voiceLinkOwnerId('link-revoked'));
    }

    public function testAnUnknownLinkIsNotFound(): void
    {
        $this->expectException(VoiceException::class);
        $this->expectExceptionMessage(VoiceException::LINK_NOT_FOUND);
        $this->expectExceptionCode(404);

        static::getContainer()->get(VoiceLinkOwnershipLoader::class)->voiceLinkOwnerId('unknown');
    }
}

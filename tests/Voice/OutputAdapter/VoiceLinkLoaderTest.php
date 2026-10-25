<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Application\Loader\VoiceLinkLoader;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

/**
 * La liste des liaisons d'un utilisateur sur la vraie base (VOX-202) : les siennes, non retirées, la plus récente d'abord.
 */
class VoiceLinkLoaderTest extends DatabaseTestCase
{
    public function testTheUserSeesHisOwnActiveLinksNewestFirstWithoutAnySecret(): void
    {
        // Given : deux liaisons actives (dont une utilisée), une retirée, une d'un autre compte
        $repository = static::getContainer()->get(VoiceLinkRepository::class);
        $repository->save(VoiceLink::create('link-old', 'user-1', new DateTimeImmutable('2026-10-20 10:00:00')));
        $repository->save(VoiceLink::load(['id' => 'link-new', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => new DateTimeImmutable('2026-10-24 10:00:00'), 'last_used_at' => new DateTimeImmutable('2026-10-25 09:30:00'), 'revoked_at' => null]));
        $repository->save(VoiceLink::create('link-revoked', 'user-1', new DateTimeImmutable('2026-10-25 08:00:00'))->revoke(new DateTimeImmutable('2026-10-25 08:30:00')));
        $repository->save(VoiceLink::create('link-other', 'user-2', new DateTimeImmutable('2026-10-25 08:00:00')));
        $this->entityManager->clear();

        // When
        $links = static::getContainer()->get(VoiceLinkLoader::class)->listOfUser('user-1');

        // Then : ni l'identifiant Alexa, ni aucun jeton
        $this->assertEquals([
            new VoiceLinkDTO('link-new', 'alexa', new DateTimeImmutable('2026-10-24 10:00:00')->format(DATE_ATOM), new DateTimeImmutable('2026-10-25 09:30:00')->format(DATE_ATOM)),
            new VoiceLinkDTO('link-old', 'alexa', new DateTimeImmutable('2026-10-20 10:00:00')->format(DATE_ATOM), null),
        ], $links);
        $this->assertStringNotContainsString('amzn1', json_encode($links, JSON_THROW_ON_ERROR));
    }

    public function testAUserWithoutLinksHasAnEmptyList(): void
    {
        $this->assertSame([], static::getContainer()->get(VoiceLinkLoader::class)->listOfUser('nobody'));
    }

    public function testTwoLinksCreatedAtTheSameSecondComeInAStableOrder(): void
    {
        // Given
        $repository = static::getContainer()->get(VoiceLinkRepository::class);
        $at = new DateTimeImmutable('2026-10-25 10:00:00');
        $repository->save(VoiceLink::create('link-b', 'user-1', $at));
        $repository->save(VoiceLink::create('link-a', 'user-1', $at));

        // When
        $ids = array_map(static fn(VoiceLinkDTO $link): string => $link->id, static::getContainer()->get(VoiceLinkLoader::class)->listOfUser('user-1'));

        // Then
        $this->assertSame(['link-a', 'link-b'], $ids);
    }
}

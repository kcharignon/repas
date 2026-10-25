<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

/**
 * Les liaisons avec Alexa sur la vraie base (VOX-202).
 */
class VoiceLinkRepositoryTest extends DatabaseTestCase
{
    private VoiceLinkRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(VoiceLinkRepository::class);
    }

    public function testALinkIsSavedAndReadBackWithAllItsFields(): void
    {
        // Given
        $link = VoiceLink::load([
            'id' => 'link-1',
            'user_id' => 'user-1',
            'alexa_user_id' => 'amzn1.ask.account.TEST',
            'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'),
            'last_used_at' => new DateTimeImmutable('2026-10-25 11:00:00'),
            'revoked_at' => new DateTimeImmutable('2026-10-25 12:00:00'),
        ]);

        // When
        $this->repository->save($link);
        $this->entityManager->clear();
        $read = $this->repository->findOneById('link-1');

        // Then
        $this->assertEquals($link, $read);
    }

    public function testSavingAnExistingLinkUpdatesItInsteadOfDuplicatingIt(): void
    {
        // Given
        $this->repository->save(VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00')));
        $this->entityManager->clear();

        // When
        $link = $this->repository->findOneById('link-1');
        $this->repository->save($link->revoke(new DateTimeImmutable('2026-10-25 12:00:00')));
        $this->entityManager->clear();

        // Then
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM voice_link'));
        $this->assertSame('2026-10-25 12:00:00', $this->repository->findOneById('link-1')->getRevokedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnUnknownLinkIsNull(): void
    {
        $this->assertNull($this->repository->findOneById('unknown'));
    }

    public function testTheLinkAwaitingAlexaIsTheMostRecentOneOfTheUserThatAlexaHasNotUsedAndThatIsNotRevoked(): void
    {
        // Given : du compte, trois liaisons qui ne conviennent pas (utilisée par Alexa, retirée, d'un autre compte) et deux qui conviennent
        $this->repository->save(VoiceLink::load(['id' => 'used', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => new DateTimeImmutable('2026-10-25 15:00:00'), 'last_used_at' => null, 'revoked_at' => null]));
        $this->repository->save(VoiceLink::create('revoked', 'user-1', new DateTimeImmutable('2026-10-25 14:00:00'))->revoke(new DateTimeImmutable('2026-10-25 14:30:00')));
        $this->repository->save(VoiceLink::create('other-user', 'user-2', new DateTimeImmutable('2026-10-25 13:00:00')));
        $this->repository->save(VoiceLink::create('awaiting-old', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00')));
        $this->repository->save(VoiceLink::create('awaiting-new', 'user-1', new DateTimeImmutable('2026-10-25 11:00:00')));
        $this->entityManager->clear();

        // When / Then
        $this->assertSame('awaiting-new', $this->repository->findAwaitingAlexaOfUser('user-1')->getId());
        $this->assertSame('other-user', $this->repository->findAwaitingAlexaOfUser('user-2')->getId());
        $this->assertNull($this->repository->findAwaitingAlexaOfUser('user-3'));
    }
}

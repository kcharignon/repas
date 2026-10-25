<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
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

    public function testARevokedLinkIsStoredWithoutItsAlexaUserId(): void
    {
        // Given : une liaison que le webhook a reconnue, puis retirée (le chemin de la révocation passe par `revoke()`)
        $this->repository->save(VoiceLink::load(['id' => 'link-1', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'), 'last_used_at' => null, 'revoked_at' => null]));
        $this->repository->save(VoiceLink::load(['id' => 'link-2', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'), 'last_used_at' => null, 'revoked_at' => null]));
        $this->entityManager->clear();

        // When
        $this->repository->save($this->repository->findOneById('link-1')->revoke(new DateTimeImmutable('2026-10-25 12:00:00')));
        $this->entityManager->clear();

        // Then : la colonne est vide pour la liaison retirée (pas seulement le modèle relu), intacte pour l'autre
        $connection = $this->entityManager->getConnection();
        $this->assertNull($connection->fetchOne('SELECT alexa_user_id FROM voice_link WHERE id = ?', ['link-1']));
        $this->assertSame('amzn1.ask.account.TEST', $connection->fetchOne('SELECT alexa_user_id FROM voice_link WHERE id = ?', ['link-2']));
        $this->assertNotNull($connection->fetchOne('SELECT revoked_at FROM voice_link WHERE id = ?', ['link-1']));
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

    public function testRecordingUseWritesOnlyTheDateWithASingleStatement(): void
    {
        // Given
        $this->repository->save(VoiceLink::load(['id' => 'link-1', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'), 'last_used_at' => new DateTimeImmutable('2026-10-25 11:00:00'), 'revoked_at' => null]));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->recordUse('link-1', new DateTimeImmutable('2026-10-25 12:00:00'));

        // Then : une seule instruction ; tout le reste est intact
        $this->assertSame(1, $counter->count());
        $this->entityManager->clear();
        $link = $this->repository->findOneById('link-1');
        $this->assertSame('2026-10-25 12:00:00', $link->getLastUsedAt()->format('Y-m-d H:i:s'));
        $this->assertSame(['user-1', 'amzn1.ask.account.TEST', '2026-10-25 10:00:00'], [$link->getUserId(), $link->getAlexaUserId(), $link->getCreatedAt()->format('Y-m-d H:i:s')]);
        $this->assertFalse($link->isRevoked());
    }

    public function testRecordingUseNeverMovesTheDateBackwardsAndIgnoresARevokedOrUnknownLink(): void
    {
        // Given
        $this->repository->save(VoiceLink::load(['id' => 'recent', 'user_id' => 'user-1', 'alexa_user_id' => null, 'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'), 'last_used_at' => new DateTimeImmutable('2026-10-25 13:00:00'), 'revoked_at' => null]));
        $this->repository->save(VoiceLink::create('revoked', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00'))->revoke(new DateTimeImmutable('2026-10-25 11:00:00')));
        $this->entityManager->clear();

        // When
        $this->repository->recordUse('recent', new DateTimeImmutable('2026-10-25 12:00:00'));
        $this->repository->recordUse('revoked', new DateTimeImmutable('2026-10-25 12:00:00'));
        $this->repository->recordUse('unknown', new DateTimeImmutable('2026-10-25 12:00:00'));
        $this->entityManager->clear();

        // Then
        $this->assertSame('2026-10-25 13:00:00', $this->repository->findOneById('recent')->getLastUsedAt()->format('Y-m-d H:i:s'));
        $this->assertNull($this->repository->findOneById('revoked')->getLastUsedAt());
        $this->assertNull($this->repository->findOneById('unknown'));
    }

    public function testTheDateIsWrittenInTheSameInstantWhateverTheTimezoneOfTheDateGiven(): void
    {
        // Given
        $this->repository->save(VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00')));
        $this->entityManager->clear();

        // When : minuit trente à Tokyo, c'est 15:30 UTC la veille
        $this->repository->recordUse('link-1', new DateTimeImmutable('2026-10-26 00:30:00', new \DateTimeZone('Asia/Tokyo')));
        $this->entityManager->clear();

        // Then
        $this->assertSame(new DateTimeImmutable('2026-10-26 00:30:00', new \DateTimeZone('Asia/Tokyo'))->getTimestamp(), $this->repository->findOneById('link-1')->getLastUsedAt()->getTimestamp());
    }

    public function testTheAlexaUserIdIsPostedOnceByASingleStatementAndNeverChanged(): void
    {
        // Given
        $this->repository->save(VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00')));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $first = $this->repository->identifyAlexaUser('link-1', 'amzn1.ask.account.FIRST');
        $queries = $counter->count();
        $second = $this->repository->identifyAlexaUser('link-1', 'amzn1.ask.account.SECOND');
        $this->entityManager->clear();

        // Then : le premier appel le pose (une instruction), le second ne change rien et le dit
        $this->assertSame([true, 1, false], [$first, $queries, $second]);
        $this->assertSame('amzn1.ask.account.FIRST', $this->repository->findOneById('link-1')->getAlexaUserId());
    }

    public function testARevokedOrUnknownLinkIsNotIdentified(): void
    {
        // Given
        $this->repository->save(VoiceLink::create('revoked', 'user-1', new DateTimeImmutable('2026-10-25 10:00:00'))->revoke(new DateTimeImmutable('2026-10-25 11:00:00')));
        $this->entityManager->clear();

        // When / Then
        $this->assertFalse($this->repository->identifyAlexaUser('revoked', 'amzn1.ask.account.ME'));
        $this->assertFalse($this->repository->identifyAlexaUser('unknown', 'amzn1.ask.account.ME'));
        $this->entityManager->clear();
        $this->assertNull($this->repository->findOneById('revoked')->getAlexaUserId());
    }

    public function testTheOtherLinksOfTheSameAccountAndTheSameAlexaUserAreFoundOldestFirst(): void
    {
        // Given : le même Echo relié trois fois, plus une liaison révoquée, une d'un autre Echo, une d'un autre compte, et celle qu'on exclut
        $me = 'amzn1.ask.account.ME';
        $load = static fn(string $id, string $user, ?string $alexa, string $created, ?string $revoked = null): VoiceLink => VoiceLink::load(['id' => $id, 'user_id' => $user, 'alexa_user_id' => $alexa, 'created_at' => new DateTimeImmutable($created), 'last_used_at' => null, 'revoked_at' => $revoked === null ? null : new DateTimeImmutable($revoked)]);
        $this->repository->save($load('newer', 'user-1', $me, '2026-10-20 10:00:00'));
        $this->repository->save($load('older', 'user-1', $me, '2026-10-10 10:00:00'));
        $this->repository->save($load('current', 'user-1', $me, '2026-10-25 10:00:00'));
        $this->repository->save($load('revoked', 'user-1', $me, '2026-10-05 10:00:00', '2026-10-06 10:00:00'));
        $this->repository->save($load('other-echo', 'user-1', 'amzn1.ask.account.OTHER', '2026-10-05 10:00:00'));
        $this->repository->save($load('other-account', 'user-2', $me, '2026-10-05 10:00:00'));
        $this->entityManager->clear();

        // When
        $others = $this->repository->findOthersOfAlexaUser('user-1', $me, 'current');

        // Then
        $this->assertSame(['older', 'newer'], array_map(static fn(VoiceLink $link): string => $link->getId(), $others));
        $this->assertSame([], $this->repository->findOthersOfAlexaUser('user-3', $me, 'current'));
    }
}

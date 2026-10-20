<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
use Repas\User\Application\RecordUserLogin\RecordUserLoginHandler;

class RecordUserLoginHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private RecordUserLoginHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-20 10:15:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->handler = new RecordUserLoginHandler($this->userRepository, new FrozenClock($this->now));
    }

    public function testTheFirstLoginOfAnAccountIsRecordedAtTheCurrentTime(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());

        // When
        ($this->handler)(new RecordUserLoginCommand('user-id'));

        // Then : un seul enregistrement ciblé, à l'heure de l'horloge
        $this->assertSame([['user-id', $this->now]], $this->userRepository->recordLoginCalls);
        $this->assertEquals($this->now, $this->userRepository->findOneById('user-id')->getLastLoginAt());
    }

    public function testALoginOlderThanTenMinutesIsReplaced(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withLastLoginAt($this->now->modify('-10 minutes'))->build());

        // When
        ($this->handler)(new RecordUserLoginCommand('user-id'));

        // Then
        $this->assertCount(1, $this->userRepository->recordLoginCalls);
        $this->assertEquals($this->now, $this->userRepository->findOneById('user-id')->getLastLoginAt());
    }

    public function testALoginWithinTenMinutesOfTheLastOneWritesNothing(): void
    {
        // Given
        $last = $this->now->modify('-9 minutes');
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withLastLoginAt($last)->build());

        // When
        ($this->handler)(new RecordUserLoginCommand('user-id'));

        // Then
        $this->assertSame([], $this->userRepository->recordLoginCalls);
        $this->assertEquals($last, $this->userRepository->findOneById('user-id')->getLastLoginAt());
    }

    public function testADisabledAccountWritesNothing(): void
    {
        // Given : l'administrateur l'a désactivé entre la vérification du mot de passe et cet enregistrement
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isDisabled()->build());

        // When
        ($this->handler)(new RecordUserLoginCommand('user-id'));

        // Then
        $this->assertSame([], $this->userRepository->recordLoginCalls);
        $this->assertNull($this->userRepository->findOneById('user-id')->getLastLoginAt());
    }

    public function testAPendingAccountWritesNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());

        // When
        ($this->handler)(new RecordUserLoginCommand('user-id'));

        // Then
        $this->assertSame([], $this->userRepository->recordLoginCalls);
    }

    public function testAnAccountDeletedInTheMeantimeDoesNotFailTheAuthentication(): void
    {
        // When : aucun compte à cet identifiant (supprimé entre l'authentification et cet enregistrement)
        ($this->handler)(new RecordUserLoginCommand('deleted-user-id'));

        // Then : ni exception, ni écriture
        $this->assertSame([], $this->userRepository->recordLoginCalls);
    }
}

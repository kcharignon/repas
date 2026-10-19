<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\UserAppVersionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionHandler;
use Repas\User\Domain\Exception\AppVersionException;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Domain\Model\UserAppVersion;

class UpdateUserAppVersionHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private UserAppVersionInMemoryRepository $userAppVersionRepository;
    private UpdateUserAppVersionHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-20 10:15:00');
        $this->userRepository = new UserInMemoryRepository([new UserBuilder()->withId('user-id')->build()]);
        $this->userAppVersionRepository = new UserAppVersionInMemoryRepository($this->userRepository);
        $this->handler = new UpdateUserAppVersionHandler($this->userAppVersionRepository, new FrozenClock($this->now));
    }

    public function testTheVersionSeenForTheAccountIsKeptWithTheTime(): void
    {
        // When
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.8'));

        // Then
        $this->assertEquals(
            [UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.8'), $this->now)],
            $this->userAppVersionRepository->all(),
        );
    }

    public function testANewVersionReplacesThePreviousOne(): void
    {
        // Given : l'application était en 0.1.8, elle a été mise à jour
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('user-id')->withVersion('0.1.8')->withSeenAt(new DateTimeImmutable('2026-10-19 08:00:00'))->build());

        // When
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.10'));

        // Then : une seule ligne, pas d'historique
        $this->assertEquals(
            [UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.10'), $this->now)],
            $this->userAppVersionRepository->all(),
        );
    }

    public function testSendingTheSameVersionTwiceGivesTheSameState(): void
    {
        // When
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.8'));
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.8'));

        // Then
        $this->assertEquals(
            [UserAppVersion::seen('user-id', AppPlatform::ANDROID, AppVersion::fromString('0.1.8'), $this->now)],
            $this->userAppVersionRepository->all(),
        );
    }

    public function testADeletedAccountGetsNoLine(): void
    {
        // Given : le compte est supprimé entre le jeton et l'écriture
        $this->userRepository->delete($this->userRepository->findOneById('user-id'));

        // When
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.8'));

        // Then : rien n'est écrit et rien n'est levé (le front ne lit pas la réponse)
        $this->assertSame(1, $this->userAppVersionRepository->saveCalls);
        $this->assertSame([], $this->userAppVersionRepository->all());
    }

    public function testTheVersionsOfOtherAccountsAreUntouched(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('other-id')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('other-id')->withVersion('0.1.7')->build());

        // When
        ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, '0.1.10'));

        // Then
        $versions = [];
        foreach ($this->userAppVersionRepository->all() as $appVersion) {
            $versions[$appVersion->getUserId()] = $appVersion->getVersion()->toString();
        }
        $this->assertSame(['other-id' => '0.1.7', 'user-id' => '0.1.10'], $versions);
    }

    #[DataProvider('invalidVersions')]
    public function testAMalformedVersionIsRefusedAndNothingIsWritten(string $version): void
    {
        // When
        try {
            ($this->handler)(new UpdateUserAppVersionCommand('user-id', AppPlatform::ANDROID, $version));
            $this->fail('Une version mal formée est refusée.');
        } catch (AppVersionException $exception) {
            // Then
            $this->assertEquals(AppVersionException::invalidVersion(), $exception);
            $this->assertSame(0, $this->userAppVersionRepository->saveCalls);
            $this->assertSame([], $this->userAppVersionRepository->all());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'quatre nombres' => ['0.1.8.1'];
        yield 'suffixe' => ['0.1.8-dev'];
        yield 'vide' => [''];
        yield 'trop longue' => ['1.1.12345678901234567'];
    }
}

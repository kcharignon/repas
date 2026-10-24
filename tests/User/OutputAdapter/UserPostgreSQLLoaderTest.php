<?php

namespace Repas\Tests\User\OutputAdapter;


use DateTimeImmutable;
use DateTimeZone;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledAppReleaseProvider;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\AppUpdateDTO;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Loader\UserPostgreSQLLoader;

class UserPostgreSQLLoaderTest extends DatabaseTestCase
{
    private UserRepository $userRepository;
    private UserAppVersionRepository $userAppVersionRepository;
    private UserLoader $userLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = static::getContainer()->get(UserRepository::class);
        $this->userAppVersionRepository = static::getContainer()->get(UserAppVersionRepository::class);
        $this->userLoader = static::getContainer()->get(UserLoader::class);
    }

    public function testGetReturnsTheDTOOfTheRequestedUser(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('first@example.com')->withServing(2)->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('second@example.com')->withServing(6)->build());

        // When
        $result = $this->userLoader->get('22222222-2222-4222-8222-222222222222');

        // Then : les réglages de version du serveur sont vides en test, personne n'est invité à mettre à jour
        $this->assertEquals(
            new CurrentUserDTO('22222222-2222-4222-8222-222222222222', 'second@example.com', ['ROLE_USER'], false, 6, new AppUpdateDTO(null, null, false)),
            $result,
        );
    }

    public function testGetInvitesToUpdateWhenTheVersionSeenIsOlderThanTheLatest(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->withVersion('0.1.8')->build());

        // When
        $result = $this->loaderWith('0.1.10', '0.1.9')->get('11111111-1111-4111-8111-111111111111');

        // Then
        $this->assertEquals(new AppUpdateDTO('0.1.10', '0.1.9', true), $result->appUpdate);
    }

    public function testGetComparesTheVersionsNumberByNumber(): void
    {
        // Given : en texte, « 0.1.10 » serait avant « 0.1.9 »
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->withVersion('0.1.10')->build());

        // When
        $result = $this->loaderWith('0.1.9', null)->get('11111111-1111-4111-8111-111111111111');

        // Then
        $this->assertEquals(new AppUpdateDTO('0.1.9', null, false), $result->appUpdate);
    }

    public function testGetNeverInvitesWhenNoVersionIsKnownForTheAccount(): void
    {
        // Given : un compte qui n'utilise que le navigateur ; un autre compte a une vieille application
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('22222222-2222-4222-8222-222222222222')->withVersion('0.0.1')->build());

        // When
        $result = $this->loaderWith('0.1.10', '0.1.9')->get('11111111-1111-4111-8111-111111111111');

        // Then : jamais d'erreur, jamais d'invitation, et la version d'un autre compte ne compte pas
        $this->assertEquals(new AppUpdateDTO('0.1.10', '0.1.9', false), $result->appUpdate);
    }

    public function testGetIgnoresMalformedServerSettings(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->withVersion('0.1.8')->build());

        // When
        $result = $this->loaderWith('0.1.10-dev', '1.0')->get('11111111-1111-4111-8111-111111111111');

        // Then
        $this->assertEquals(new AppUpdateDTO(null, null, false), $result->appUpdate);
    }

    public function testGetReadsTheAccountAndItsVersionInOneQuery(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->build());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loaderWith('0.1.10', null)->get('11111111-1111-4111-8111-111111111111');

        // Then
        $this->assertSame(1, $counter->count());
    }

    public function testListReturnsEveryUserWithItsStatisticsSortedByEmail(): void
    {
        // Given
        $created = new DateTimeImmutable('2026-10-13 08:56:40', new DateTimeZone('Europe/Paris'));
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('zzz@example.com')->withServing(2)
            ->withCreatedAt($created)->withRecipeAndIngredientStats(7, 5)->isDisabled()->build());
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('aaa@example.com')->withServing(6)
            ->withCreatedAt($created)->isAdmin()->build());
        $this->userRepository->save(new UserBuilder()->withId('33333333-3333-4333-8333-333333333333')->withEmail('mmm@example.com')->withServing(3)
            ->withCreatedAt($created)->isPending()->build());

        // When
        $result = $this->userLoader->list();

        // Then
        $this->assertEquals(
            [
                new UserSummaryDTO('11111111-1111-4111-8111-111111111111', 'aaa@example.com', 6, 0, 0, 0, '2026-10-13T08:56:40+02:00', null, false, true, 'ACTIVE', null, false),
                new UserSummaryDTO('33333333-3333-4333-8333-333333333333', 'mmm@example.com', 3, 0, 0, 0, '2026-10-13T08:56:40+02:00', null, false, false, 'PENDING', null, false),
                new UserSummaryDTO('22222222-2222-4222-8222-222222222222', 'zzz@example.com', 2, 7, 5, 0, '2026-10-13T08:56:40+02:00', null, true, false, 'DISABLED', null, false),
            ],
            $result,
        );
    }

    public function testListShowsTheLastLoginOfEachAccountAndNullForAnAccountNeverSeen(): void
    {
        // Given : un compte connecté il y a peu, un compte connecté il y a longtemps, un compte jamais vu depuis le suivi
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('a-recent@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('b-ancien@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('33333333-3333-4333-8333-333333333333')->withEmail('c-jamais@example.com')->build());
        $this->userRepository->recordLogin('11111111-1111-4111-8111-111111111111', new DateTimeImmutable('2026-10-20 08:30:12', new DateTimeZone('UTC')));
        $this->userRepository->recordLogin('22222222-2222-4222-8222-222222222222', new DateTimeImmutable('2026-09-01 22:05:00', new DateTimeZone('UTC')));
        $this->entityManager->clear();

        // When
        $result = $this->userLoader->list();

        // Then : date ATOM, ou `null`, et la clé est toujours écrite (le DTO a toujours la propriété)
        $this->assertSame(
            ['a-recent@example.com' => '2026-10-20T08:30:12+00:00', 'b-ancien@example.com' => '2026-09-01T22:05:00+00:00', 'c-jamais@example.com' => null],
            array_combine(
                array_map(static fn(UserSummaryDTO $user): string => $user->email, $result),
                array_map(static fn(UserSummaryDTO $user): ?string => $user->lastLoginAt, $result),
            ),
        );
        $this->assertArrayHasKey('lastLoginAt', get_object_vars($result[2]));
    }

    public function testListShowsTheAppVersionOfEachAccountAndWhetherItIsToBeUpdated(): void
    {
        // Given : APP_LATEST_VERSION=0.1.10
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('a-ancienne@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('b-a-jour@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('33333333-3333-4333-8333-333333333333')->withEmail('c-navigateur@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('44444444-4444-4444-8444-444444444444')->withEmail('d-plus-recente@example.com')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->withVersion('0.1.8')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('22222222-2222-4222-8222-222222222222')->withVersion('0.1.10')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('44444444-4444-4444-8444-444444444444')->withVersion('1.0.0')->build());

        // When
        $result = $this->loaderWith('0.1.10', null)->list();

        // Then
        $this->assertSame(
            [
                'a-ancienne@example.com' => ['0.1.8', true],
                'b-a-jour@example.com' => ['0.1.10', false],
                'c-navigateur@example.com' => [null, false],
                'd-plus-recente@example.com' => ['1.0.0', false],
            ],
            array_combine(
                array_map(static fn(UserSummaryDTO $user): string => $user->email, $result),
                array_map(static fn(UserSummaryDTO $user): array => [$user->appVersion, $user->appOutdated], $result),
            ),
        );
    }

    public function testListComparesTheVersionsNumberByNumber(): void
    {
        // Given : APP_LATEST_VERSION=1.0.0 ; en texte, « 0.99.99 » et « 0.1.10 » se trieraient mal
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('a@example.com')->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('b@example.com')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('11111111-1111-4111-8111-111111111111')->withVersion('0.99.99')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('22222222-2222-4222-8222-222222222222')->withVersion('1.0.0')->build());

        // When
        $result = $this->loaderWith('1.0.0', null)->list();

        // Then
        $this->assertSame([true, false], array_map(static fn(UserSummaryDTO $user): bool => $user->appOutdated, $result));
    }

    public function testListReadsEveryAccountAndItsVersionInOneQuery(): void
    {
        // Given
        foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'] as $id) {
            $this->userRepository->save(new UserBuilder()->withId($id)->build());
            $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId($id)->build());
        }
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->loaderWith('0.1.10', null)->list();

        // Then : aucune requête par compte
        $this->assertCount(3, $result);
        $this->assertSame(1, $counter->count());
    }

    public function testListIsEmptyWithoutUser(): void
    {
        // When
        $result = $this->userLoader->list();

        // Then
        $this->assertSame([], $result);
    }

    public function testEmailExistsOnlyForAnExistingEmail(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('existing@example.com')->build());

        // When & Then
        $this->assertTrue($this->userLoader->emailExists('existing@example.com'));
        $this->assertFalse($this->userLoader->emailExists('other@example.com'));
    }

    public function testEmailExistsIgnoresTheCase(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('existing@example.com')->build());

        // When & Then
        $this->assertTrue($this->userLoader->emailExists('Existing@Example.COM'));
    }

    public function testGetFlagsAnAdministrator(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('33333333-3333-4333-8333-333333333333')->withEmail('admin@example.com')->isAdmin()->build());

        // When
        $result = $this->userLoader->get('33333333-3333-4333-8333-333333333333');

        // Then
        $this->assertTrue($result->isAdmin);
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $result->roles);
    }

    public function testGetDoesNotExposeTheCredentials(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('44444444-4444-4444-8444-444444444444')->build());

        // When
        $result = $this->userLoader->get('44444444-4444-4444-8444-444444444444');

        // Then
        $this->assertSame(['id', 'email', 'roles', 'isAdmin', 'defaultServing', 'appUpdate'], array_keys(get_object_vars($result)));
    }

    public function testGetThrowsWhenTheUserDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(UserException::NotFound('99999999-9999-4999-8999-999999999999'));

        // When
        $this->userLoader->get('99999999-9999-4999-8999-999999999999');
    }

    /**
     * Le loader réel, avec les versions publiées choisies par le test (`APP_LATEST_VERSION`, `APP_MIN_VERSION`).
     */
    private function loaderWith(?string $latest, ?string $minimum): UserLoader
    {
        return new UserPostgreSQLLoader($this->entityManager, new ControlledAppReleaseProvider($latest, $minimum));
    }
}

<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledSecretGenerator;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\PasswordResetTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\SpyRegistrationNotifier;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Application\RegisterNewUser\RegisterNewUserHandler;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Domain\Service\WelcomeLinkIssuer;

class RegisterNewUserHandlerTest extends TestCase
{
    private readonly RegisterNewUserHandler $handler;
    private readonly UserInMemoryRepository $userRepository;
    private readonly PasswordResetTokenInMemoryRepository $tokenRepository;
    private readonly SpyRegistrationNotifier $notifier;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new PasswordResetTokenInMemoryRepository();
        $this->notifier = new SpyRegistrationNotifier();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-28 12:00:00'));
        $this->handler = new RegisterNewUserHandler(
            $this->userRepository,
            $this->clock,
            new WelcomeLinkIssuer(
                $this->tokenRepository,
                $this->notifier,
                new ControlledSecretGenerator(['the-secret']),
                new ControlledUuidGenerator(['token-id']),
                $this->clock,
            ),
            $this->notifier,
        );
    }

    public function testANewAddressGetsAPendingAccountWithoutAPasswordAndAWelcomeLink(): void
    {
        // When
        ($this->handler)(new RegisterNewUserCommand('new-user-id', 'john.doe@example.com', 5));

        // Then : le compte attend le clic sur le lien, et personne ne connaît de mot de passe pour lui
        $user = $this->userRepository->findOneByEmail('john.doe@example.com');
        $this->assertSame('new-user-id', $user->getId());
        $this->assertSame(UserStatus::PENDING, $user->getStatus());
        $this->assertSame(5, $user->getDefaultServing());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertFalse($user->hasPassword());
        $this->assertSame(User::NO_PASSWORD, $user->getPassword());
        $this->assertSame('2026-10-28', $user->getCreatedAt());
    }

    public function testStoresOnlyTheHashOfTheSecretForTwentyFourHoursAndSendsTheSecretByEmail(): void
    {
        // When
        ($this->handler)(new RegisterNewUserCommand('new-user-id', 'john.doe@example.com', 5));

        // Then
        $this->assertEquals(
            [PasswordResetToken::issueWelcome('token-id', 'new-user-id', PasswordResetToken::hash('the-secret'), $this->clock->now())],
            $this->tokenRepository->all(),
        );
        $this->assertSame('2026-10-29 12:00:00', $this->tokenRepository->all()[0]->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'the-secret']], $this->notifier->welcomes);
        $this->assertSame([], $this->notifier->reminders);
    }

    public function testStoresTheEmailInLowercaseWithoutSurroundingSpaces(): void
    {
        // When
        ($this->handler)(new RegisterNewUserCommand('new-user-id', '  John.Doe@Example.COM ', 5));

        // Then : le compte et l'email envoyé portent l'adresse normalisée
        $this->assertSame('john.doe@example.com', $this->userRepository->findOneById('new-user-id')->getEmail());
        $this->assertSame('john.doe@example.com', $this->notifier->welcomes[0]['email']);
    }

    public function testAnActiveAccountIsToldItExistsAndNothingAboutItChanges(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('active-id')->withEmail('john.doe@example.com')->withServing(2)->build());
        $before = $this->userRepository->findOneById('active-id')->toArray();

        // When : l'adresse est écrite autrement
        ($this->handler)(new RegisterNewUserCommand('other-id', 'JOHN.DOE@example.com', 5));

        // Then : un rappel à l'adresse du compte, ni compte de plus, ni lien, ni mot de passe touché
        $this->assertSame(['john.doe@example.com'], $this->notifier->reminders);
        $this->assertSame([], $this->notifier->welcomes);
        $this->assertSame([], $this->tokenRepository->all());
        $this->assertEquals($before, $this->userRepository->findOneById('active-id')->toArray());
        $this->assertSame(1, $this->userRepository->findAll()->count());
    }

    public function testAPendingAccountGetsANewWelcomeLinkThatReplacesTheOldOne(): void
    {
        // Given : un compte inscrit hier, dont le lien n'a pas servi
        $this->userRepository->save(new UserBuilder()->withId('pending-id')->withEmail('john.doe@example.com')->withoutPassword()->isPending()->build());
        $this->tokenRepository->add(PasswordResetToken::issueWelcome('old-token-id', 'pending-id', PasswordResetToken::hash('old-secret'), $this->clock->now()->modify('-1 day')));

        // When
        ($this->handler)(new RegisterNewUserCommand('other-id', 'john.doe@example.com', 5));

        // Then : le premier lien ne sert plus, un seul est valable, le compte est le même
        $this->assertNull($this->tokenRepository->findOneByTokenHash(PasswordResetToken::hash('old-secret')));
        $this->assertEquals(
            [PasswordResetToken::issueWelcome('token-id', 'pending-id', PasswordResetToken::hash('the-secret'), $this->clock->now())],
            $this->tokenRepository->all(),
        );
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'the-secret']], $this->notifier->welcomes);
        $this->assertSame([], $this->notifier->reminders);
        $this->assertSame(1, $this->userRepository->findAll()->count());
    }

    public function testAPendingAccountOfTheOldFormIsNotGivenAnotherPasswordByTheRegistration(): void
    {
        // Given : un compte en attente avec son ancien mot de passe
        $this->userRepository->save(new UserBuilder()->withId('pending-id')->withEmail('john.doe@example.com')->isPending()->build());

        // When
        ($this->handler)(new RegisterNewUserCommand('other-id', 'john.doe@example.com', 5));

        // Then : rien dans la demande ne fixe un mot de passe ; seul le lien le fera
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'the-secret']], $this->notifier->welcomes);
        $this->assertSame(UserStatus::PENDING, $this->userRepository->findOneById('pending-id')->getStatus());
    }

    public function testADisabledAccountReceivesNothingAndChangesNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('disabled-id')->withEmail('john.doe@example.com')->isDisabled()->build());
        $before = $this->userRepository->findOneById('disabled-id')->toArray();

        // When
        ($this->handler)(new RegisterNewUserCommand('other-id', 'john.doe@example.com', 5));

        // Then : ni email, ni lien, ni réactivation (ADR 0021)
        $this->assertSame(0, $this->notifier->count());
        $this->assertSame([], $this->tokenRepository->all());
        $this->assertEquals($before, $this->userRepository->findOneById('disabled-id')->toArray());
        $this->assertSame(1, $this->userRepository->findAll()->count());
    }

    /**
     * @return iterable<string, array{?UserBuilder, int}>
     */
    public static function everyKindOfAddress(): iterable
    {
        yield 'adresse nouvelle' => [null, 1];
        yield 'compte en attente' => [new UserBuilder()->withEmail('john.doe@example.com')->withoutPassword()->isPending(), 1];
        yield 'compte actif' => [new UserBuilder()->withEmail('john.doe@example.com'), 1];
        yield 'compte désactivé' => [new UserBuilder()->withEmail('john.doe@example.com')->isDisabled(), 0];
    }

    /**
     * Le temps de réponse est dominé par l'envoi de l'email : chaque cas qui écrit à l'adresse en fait exactement un, et un seul.
     */
    #[DataProvider('everyKindOfAddress')]
    public function testEveryAddressThatIsWrittenToCostsExactlyOneEmail(?UserBuilder $existing, int $emails): void
    {
        // Given
        if ($existing !== null) {
            $this->userRepository->save($existing->build());
        }

        // When
        ($this->handler)(new RegisterNewUserCommand('new-user-id', 'john.doe@example.com', 5));

        // Then
        $this->assertSame($emails, $this->notifier->count());
    }

    public function testTheCommandCarriesNoPasswordAtAll(): void
    {
        // Then : un mot de passe envoyé par un ancien client est ignoré dès le controller, il ne traverse jamais le bus
        $parameters = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            new ReflectionClass(RegisterNewUserCommand::class)->getConstructor()->getParameters(),
        );
        $this->assertSame(['userId', 'email', 'defaultServing'], $parameters);
    }
}

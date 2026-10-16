<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledSecretGenerator;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\DummyPasswordHasher;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\SpyEmailVerificationNotifier;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Application\RegisterNewUser\RegisterNewUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Domain\Service\EmailVerificationIssuer;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class RegisterNewUserHandlerTest extends TestCase
{
    private readonly RegisterNewUserHandler $handler;
    private readonly PasswordHasherFactoryInterface $passwordHasherFactory;
    private readonly UserRepository $userRepository;
    private readonly EmailVerificationTokenInMemoryRepository $tokenRepository;
    private readonly SpyEmailVerificationNotifier $notifier;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->passwordHasherFactory = new DummyPasswordHasher();
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->notifier = new SpyEmailVerificationNotifier();
        $this->clock = new FrozenClock(new DateTimeImmutable());
        $this->handler = new RegisterNewUserHandler(
            $this->passwordHasherFactory,
            $this->userRepository,
            $this->clock,
            new EmailVerificationIssuer(
                $this->tokenRepository,
                $this->notifier,
                new ControlledSecretGenerator(['the-secret']),
                new ControlledUuidGenerator(['token-id']),
                $this->clock,
            ),
        );
    }

    public function testSuccessfullyHandleRegisterNewUser(): void
    {
        // Arrange
        $command = new RegisterNewUserCommand(
            "new-user-id",
            "john.doe@example.com",
            "megapassword",
            5,
        );

        // Act
        ($this->handler)($command);

        // Assert : le compte attend la confirmation de son adresse
        $expected = new UserBuilder()
            ->withId("new-user-id")
            ->withEmail("john.doe@example.com")
            ->withServing(5)
            ->withPassword($this->passwordHasherFactory->hash("megapassword"))
            ->withCreatedAt($this->clock->now())
            ->isPending()
            ->build();
        $actual = $this->userRepository->findOneByEmail("john.doe@example.com");
        RepasAssert::assertUser($expected, $actual);
        $this->assertSame(UserStatus::PENDING, $actual->getStatus());
    }

    public function testStoresOnlyTheHashOfTheSecretAndSendsTheSecretByEmail(): void
    {
        // Act
        ($this->handler)(new RegisterNewUserCommand("new-user-id", "john.doe@example.com", "megapassword", 5));

        // Assert
        $this->assertEquals(
            [EmailVerificationToken::issue('token-id', 'new-user-id', EmailVerificationToken::hash('the-secret'), $this->clock->now())],
            $this->tokenRepository->all(),
        );
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'the-secret']], $this->notifier->sent);
    }

    public function testStoresTheEmailInLowercaseWithoutSurroundingSpaces(): void
    {
        // Act
        ($this->handler)(new RegisterNewUserCommand("new-user-id", "  John.Doe@Example.COM ", "megapassword", 5));

        // Assert : le compte et l'email envoyé portent l'adresse normalisée
        $this->assertSame("john.doe@example.com", $this->userRepository->findOneById("new-user-id")->getEmail());
        $this->assertSame("john.doe@example.com", $this->notifier->sent[0]['email']);
    }

    public function testRefusesAnEmailThatDiffersOnlyByCase(): void
    {
        // Arrange
        $this->userRepository->save(new UserBuilder()->withEmail("john.doe@example.com")->build());

        // Assert
        $this->expectExceptionObject(UserException::emailAlreadyUsed("JOHN.DOE@example.com"));

        // Act
        ($this->handler)(new RegisterNewUserCommand("other-id", "JOHN.DOE@example.com", "megapassword", 5));
    }

    public function testRefusesAnEmailThatIsAlreadyUsed(): void
    {
        // Arrange : un second compte du même email échouerait sur l'unicité en base
        $this->userRepository->save(new UserBuilder()->withEmail("john.doe@example.com")->withServing(2)->build());

        // Assert
        $this->expectExceptionObject(UserException::emailAlreadyUsed("john.doe@example.com"));

        // Act
        try {
            ($this->handler)(new RegisterNewUserCommand("other-id", "john.doe@example.com", "megapassword", 5));
        } finally {
            $this->assertSame(2, $this->userRepository->findOneByEmail("john.doe@example.com")->getDefaultServing());
            // Aucun lien n'est envoyé pour une adresse refusée
            $this->assertSame([], $this->notifier->sent);
            $this->assertSame([], $this->tokenRepository->all());
        }
    }
}

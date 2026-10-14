<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\DummyPasswordHasher;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Application\RegisterNewUser\RegisterNewUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class RegisterNewUserHandlerTest extends TestCase
{
    private readonly RegisterNewUserHandler $handler;
    private readonly PasswordHasherFactoryInterface $passwordHasherFactory;
    private readonly UserRepository $userRepository;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->passwordHasherFactory = new DummyPasswordHasher();
        $this->userRepository = new UserInMemoryRepository();
        $this->clock = new FrozenClock(new DateTimeImmutable());
        $this->handler = new RegisterNewUserHandler(
            $this->passwordHasherFactory,
            $this->userRepository,
            $this->clock
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

        // Assert
        $expected = new UserBuilder()
            ->withId("new-user-id")
            ->withEmail("john.doe@example.com")
            ->withServing(5)
            ->withPassword($this->passwordHasherFactory->hash("megapassword"))
            ->withCreatedAt($this->clock->now())
            ->build();
        $actual = $this->userRepository->findOneByEmail("john.doe@example.com");
        RepasAssert::assertUser($expected, $actual);
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
        }
    }
}

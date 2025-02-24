<?php

namespace Repas\User\Application\RegisterNewUser;

use Repas\Shared\Domain\Clock;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsMessageHandler]
readonly class RegisterNewUserHandler
{
    public function __construct(
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private UserRepository $userRepository,
        private Clock $clock,
    ) {
    }

    public function __invoke(RegisterNewUserCommand $command): void
    {
        $passwordHashed = $this->passwordHasherFactory
            ->getPasswordHasher(User::class)
            ->hash($command->passwordPlainText);
        $user = User::create(
            id: UuidGenerator::new(),
            email: $command->email,
            roles: ['ROLE_USER'],
            password: $passwordHashed,
            defaultServing: $command->defaultServing,
            createdAt: $this->clock->now(),
        );

        $this->userRepository->save($user);
    }

}

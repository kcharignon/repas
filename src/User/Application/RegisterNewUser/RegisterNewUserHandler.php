<?php

namespace Repas\User\Application\RegisterNewUser;

use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
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
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(RegisterNewUserCommand $command): void
    {
        if ($this->userRepository->emailExists($command->email)) {
            throw UserException::emailAlreadyUsed($command->email);
        }

        $passwordHashed = $this->passwordHasherFactory
            ->getPasswordHasher(User::class)
            ->hash($command->passwordPlainText);
        $user = User::create(
            id: $command->userId,
            email: $command->email,
            roles: ['ROLE_USER'],
            password: $passwordHashed,
            defaultServing: $command->defaultServing,
            createdAt: $this->clock->now(),
        );

        $this->userRepository->save($user);
    }

}

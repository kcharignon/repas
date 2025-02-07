<?php

namespace Repas\User\Application\UpdateUserSettings;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsMessageHandler]
readonly class UpdateUserSettingsHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordHasherFactoryInterface $passwordHasherFactory
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(UpdateUserSettingsCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $user->update($command->defaultServing);

        if ($command->newPassword) {
            $hashedPassword = $this->passwordHasherFactory->getPasswordHasher(User::class)->hash($command->newPassword);
            $user->setPassword($hashedPassword);
        }

        $this->userRepository->save($user);
    }

}

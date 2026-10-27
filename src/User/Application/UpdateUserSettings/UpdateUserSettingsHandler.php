<?php

namespace Repas\User\Application\UpdateUserSettings;


use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\PasswordAttemptLimiter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsMessageHandler]
readonly class UpdateUserSettingsHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private PasswordAttemptLimiter $passwordAttemptLimiter,
    ) {
    }

    /**
     * Changer le mot de passe exige le mot de passe actuel, dont les refus sont plafonnés par utilisateur : au-delà, rien n'est comparé.
     *
     * @throws UserException
     * @throws RateLimitedException
     */
    public function __invoke(UpdateUserSettingsCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        if ($command->newPassword) {
            $this->passwordAttemptLimiter->assertAllowed($user->getId());
            if (!$user->passwordMatch($command->actualPassword ?? '')) {
                $this->passwordAttemptLimiter->recordFailure($user->getId());
                throw UserException::invalidPassword();
            }

            $hashedPassword = $this->passwordHasherFactory->getPasswordHasher(User::class)->hash($command->newPassword);
            $user->changePassword($hashedPassword);
        }

        $user->update($command->defaultServing);

        $this->userRepository->save($user);
    }

}

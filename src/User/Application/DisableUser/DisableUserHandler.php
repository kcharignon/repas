<?php

namespace Repas\User\Application\DisableUser;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DisableUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
    ) {
    }

    /**
     * @throws UserException compte inconnu (404), le sien (422), ou déjà désactivé (409)
     */
    public function __invoke(DisableUserCommand $command): void
    {
        if ($command->userId === $command->actingUserId) {
            throw UserException::cannotManageOwnAccount();
        }

        $user = $this->userRepository->findOneById($command->userId);

        $user->disable();

        $this->userRepository->save($user);
        // Plus rien ne doit permettre de rouvrir une connexion : ni le cookie de rafraîchissement, ni un lien de confirmation
        $this->refreshTokenRepository->deleteAllOfUser($user->getId());
        $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
    }
}

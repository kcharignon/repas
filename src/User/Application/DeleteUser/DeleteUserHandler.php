<?php

namespace Repas\User\Application\DeleteUser;


use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserIdentityRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DeleteUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private UserIdentityRepository $userIdentityRepository,
        private UserAppVersionRepository $userAppVersionRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Supprime le compte, ses jetons, ses liens d'identité externe (ADR 0024) et les versions de son application (DRV-637), puis
     * annonce la suppression : les contextes `Repas` et `Drive` effacent leurs données dans la même transaction du bus de
     * commandes, donc rien n'est supprimé si l'une des étapes échoue.
     *
     * @throws UserException compte inconnu (404), le sien ou le compte protégé (422)
     */
    public function __invoke(DeleteUserCommand $command): void
    {
        if ($command->userId === $command->actingUserId) {
            throw UserException::cannotDeleteOwnAccount();
        }

        $user = $this->userRepository->findOneById($command->userId);
        $user->assertCanBeDeleted();

        $this->refreshTokenRepository->deleteAllOfUser($user->getId());
        $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
        $this->passwordResetTokenRepository->deleteAllOfUser($user->getId());
        $this->userIdentityRepository->deleteAllOfUser($user->getId());
        $this->userRepository->delete($user);
        // Après le compte, pas avant : une écriture de la version qui croise la suppression attend la ligne du compte, puis
        // l'efface ici, ou ne trouve plus le compte et n'écrit rien (UserAppVersionRepository::save) ; la ligne ne renaît pas.
        $this->userAppVersionRepository->deleteAllOfUser($user->getId());

        $this->eventDispatcher->dispatch(new UserDeletedEvent($user->getId()));
    }
}

<?php

namespace Repas\User\Application\DeleteUser;


use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
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
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Supprime le compte et ses jetons, puis annonce la suppression : le contexte `Repas` efface ses données dans la même
     * transaction du bus de commandes, donc rien n'est supprimé si l'une des étapes échoue.
     *
     * @throws UserException compte inconnu (404), ou le sien (422)
     */
    public function __invoke(DeleteUserCommand $command): void
    {
        if ($command->userId === $command->actingUserId) {
            throw UserException::cannotManageOwnAccount();
        }

        $user = $this->userRepository->findOneById($command->userId);

        $this->refreshTokenRepository->deleteAllOfUser($user->getId());
        $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
        $this->userRepository->delete($user);

        $this->eventDispatcher->dispatch(new UserDeletedEvent($user->getId()));
    }
}

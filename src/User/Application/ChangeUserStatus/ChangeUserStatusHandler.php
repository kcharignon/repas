<?php

namespace Repas\User\Application\ChangeUserStatus;


use Repas\Shared\Domain\Event\UserActivatedEvent;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\UserStatus;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ChangeUserStatusHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Un compte qui devient actif (depuis `PENDING` ou `DISABLED`) l'annonce (`UserActivatedEvent`), dans la transaction du bus de commandes,
     * pour qu'il reçoive ses ingrédients (ING-008) ; un compte actif qui le reste n'annonce rien.
     *
     * @throws UserException compte inconnu (404), ou compte protégé qu'on désactiverait (422)
     */
    public function __invoke(ChangeUserStatusCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $wasActive = $user->isActive();
        $user->changeStatus($command->status);

        $this->userRepository->save($user);

        // Un compte qui n'est plus actif ne doit pas pouvoir rouvrir une connexion avec le cookie qu'il détient
        if ($command->status !== UserStatus::ACTIVE) {
            $this->refreshTokenRepository->deleteAllOfUser($user->getId());
        }
        // Un compte actif n'a plus besoin de confirmer son adresse, un compte désactivé ne doit pas pouvoir la confirmer
        if ($command->status !== UserStatus::PENDING) {
            $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
        }

        if ($command->status === UserStatus::ACTIVE && !$wasActive) {
            $this->eventDispatcher->dispatch(new UserActivatedEvent($user->getId()));
        }
    }
}

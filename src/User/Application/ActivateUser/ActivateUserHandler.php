<?php

namespace Repas\User\Application\ActivateUser;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ActivateUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
    ) {
    }

    /**
     * @throws UserException compte inconnu (404), ou qui n'est pas en attente (409)
     */
    public function __invoke(ActivateUserCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $user->activate();

        $this->userRepository->save($user);
        // Le lien reçu par email n'a plus d'objet : il ne doit pas rester valable
        $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
    }
}

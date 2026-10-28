<?php

namespace Repas\User\Application\RegisterNewUser;

use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\RegistrationNotifier;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Domain\Service\WelcomeLinkIssuer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RegisterNewUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private WelcomeLinkIssuer $welcomeLinkIssuer,
        private RegistrationNotifier $notifier,
    ) {
    }

    /**
     * La réponse de l'API est la même dans tous les cas, et chaque cas qui écrit à
     * l'adresse fait un envoi d'email, pour que le temps de réponse ne les distingue pas non plus (ADR 0020 amendé).
     *
     * - adresse nouvelle : un compte en attente SANS mot de passe (`User::NO_PASSWORD`) et le lien de bienvenue qui lui fait choisir
     *   le sien ;
     * - compte en attente : un nouveau lien de bienvenue (l'ancien ne sert plus) ;
     * - compte actif : un email qui dit qu'il existe et mène à la connexion et au mot de passe oublié ;
     * - compte désactivé : rien, comme le mot de passe oublié (ADR 0021).
     *
     * Aucun plafond de délai ici : le plafond par adresse saisie (`PublicEmailRateLimitListener`) borne les envois vers une même adresse.
     */
    public function __invoke(RegisterNewUserCommand $command): void
    {
        try {
            $user = $this->userRepository->findOneByEmail($command->email);
        } catch (UserException) {
            $this->registerNewAddress($command);

            return;
        }

        match ($user->getStatus()) {
            UserStatus::PENDING => $this->welcomeLinkIssuer->reissueFor($user),
            UserStatus::ACTIVE => $this->notifier->sendAlreadyRegistered($user->getEmail()),
            UserStatus::DISABLED => null,
        };
    }

    private function registerNewAddress(RegisterNewUserCommand $command): void
    {
        $user = User::register(
            id: $command->userId,
            email: $command->email,
            roles: ['ROLE_USER'],
            defaultServing: $command->defaultServing,
            createdAt: $this->clock->now(),
        );

        $this->userRepository->save($user);
        $this->welcomeLinkIssuer->issueFor($user);
    }
}

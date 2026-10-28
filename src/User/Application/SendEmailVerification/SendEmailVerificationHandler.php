<?php

namespace Repas\User\Application\SendEmailVerification;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Service\EmailVerificationIssuer;
use Repas\User\Domain\Service\WelcomeLinkIssuer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendEmailVerificationHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private EmailVerificationTokenRepository $tokenRepository,
        private EmailVerificationIssuer $emailVerificationIssuer,
        private PasswordResetTokenRepository $welcomeTokenRepository,
        private WelcomeLinkIssuer $welcomeLinkIssuer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ne dit jamais si l'adresse a un compte : un compte inconnu, déjà confirmé ou désactivé, et un envoi trop rapproché du
     * précédent, ne font rien et ne lèvent rien (sinon l'endpoint public révélerait quelles adresses sont inscrites).
     *
     * Un compte en attente SANS mot de passe (inscrit avec sa seule adresse, ADR 0020 amendé) ne se termine pas par la confirmation de
     * l'adresse mais par le choix de son mot de passe : on lui renvoie donc le lien de bienvenue, au délai de grâce près. Confirmer son
     * adresse l'activerait sans qu'il puisse se connecter. Le lien de confirmation ne reste que pour un compte qui a encore un mot de
     * passe posé avant sa confirmation (ancien formulaire).
     */
    public function __invoke(SendEmailVerificationCommand $command): void
    {
        try {
            $user = $this->userRepository->findOneByEmail($command->email);
        } catch (UserException) {
            return;
        }

        if (!$user->isPending()) {
            return;
        }

        if (!$user->hasPassword()) {
            $lastWelcome = $this->welcomeTokenRepository->findOneByUserId($user->getId());
            if ($lastWelcome === null || !$lastWelcome->wasIssuedRecently($this->clock->now())) {
                $this->welcomeLinkIssuer->reissueFor($user);
            }

            return;
        }

        $lastToken = $this->tokenRepository->findOneByUserId($user->getId());
        if ($lastToken !== null && $lastToken->wasIssuedRecently($this->clock->now())) {
            return;
        }

        $this->emailVerificationIssuer->reissueFor($user);
    }
}

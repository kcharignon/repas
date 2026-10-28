<?php

namespace Repas\User\Domain\Service;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\RegistrationNotifier;
use Repas\User\Domain\Interface\SecretGenerator;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\User;

/**
 * Émet le lien de bienvenue d'une inscription et l'envoie (ADR 0020 amendé). C'est un `PasswordResetToken` valable
 * `PasswordResetToken::WELCOME_TTL` : le même écran, le même cas d'usage (`ResetPassword`) que le mot de passe oublié, qui fixe le mot de
 * passe, active le compte en attente et ferme les sessions. Le secret naît ici, n'est stocké que haché et ne sort que par l'email. Un
 * seul lien est valable à la fois par compte (le nouveau remplace l'ancien, de bienvenue ou de mot de passe oublié).
 */
readonly class WelcomeLinkIssuer
{
    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private RegistrationNotifier $notifier,
        private SecretGenerator $secretGenerator,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Premier lien d'un compte qui vient d'être créé (il n'a pas encore de jeton à remplacer).
     */
    public function issueFor(User $user): void
    {
        $this->issue($user);
    }

    /**
     * Nouveau lien pour un compte qui en a déjà reçu un : le nouveau invalide l'ancien.
     */
    public function reissueFor(User $user): void
    {
        $this->tokenRepository->deleteAllOfUser($user->getId());
        $this->issue($user);
    }

    private function issue(User $user): void
    {
        $secret = $this->secretGenerator->generate();

        $this->tokenRepository->add(PasswordResetToken::issueWelcome(
            id: $this->uuidGenerator->generate(),
            userId: $user->getId(),
            tokenHash: PasswordResetToken::hash($secret),
            now: $this->clock->now(),
        ));

        $this->notifier->sendWelcome($user->getEmail(), $secret);
    }
}

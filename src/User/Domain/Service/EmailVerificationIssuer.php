<?php

namespace Repas\User\Domain\Service;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\EmailVerificationNotifier;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\SecretGenerator;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\User;

/**
 * Émet un lien de confirmation d'adresse email pour un utilisateur et le lui envoie. Partagé par l'inscription et le
 * renvoi du lien (ADR 0020). Le secret naît ici, n'est stocké que haché et ne sort que par l'email.
 */
readonly class EmailVerificationIssuer
{
    public function __construct(
        private EmailVerificationTokenRepository $tokenRepository,
        private EmailVerificationNotifier $notifier,
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
     * Nouveau lien pour un compte qui en a déjà reçu un : le nouveau invalide l'ancien (un seul lien valable à la fois).
     */
    public function reissueFor(User $user): void
    {
        $this->tokenRepository->deleteAllOfUser($user->getId());
        $this->issue($user);
    }

    private function issue(User $user): void
    {
        $secret = $this->secretGenerator->generate();

        $this->tokenRepository->add(EmailVerificationToken::issue(
            id: $this->uuidGenerator->generate(),
            userId: $user->getId(),
            tokenHash: EmailVerificationToken::hash($secret),
            now: $this->clock->now(),
        ));

        $this->notifier->send($user->getEmail(), $secret);
    }
}

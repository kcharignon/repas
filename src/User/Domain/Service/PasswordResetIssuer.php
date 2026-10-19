<?php

namespace Repas\User\Domain\Service;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\PasswordResetNotifier;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\SecretGenerator;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\User;

/**
 * Émet un lien de réinitialisation du mot de passe pour un utilisateur et le lui envoie (ADR 0026). Le secret naît ici,
 * n'est stocké que haché et ne sort que par l'email. Le nouveau lien invalide l'ancien (un seul lien valable à la fois).
 */
readonly class PasswordResetIssuer
{
    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private PasswordResetNotifier $notifier,
        private SecretGenerator $secretGenerator,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function issueFor(User $user): void
    {
        $this->tokenRepository->deleteAllOfUser($user->getId());

        $secret = $this->secretGenerator->generate();

        $this->tokenRepository->add(PasswordResetToken::issue(
            id: $this->uuidGenerator->generate(),
            userId: $user->getId(),
            tokenHash: PasswordResetToken::hash($secret),
            now: $this->clock->now(),
        ));

        $this->notifier->send($user->getEmail(), $secret);
    }
}

<?php

namespace Repas\User\Application\SendPasswordReset;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Service\PasswordResetIssuer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendPasswordResetHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private PasswordResetIssuer $passwordResetIssuer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ne dit jamais si l'adresse a un compte : une adresse inconnue, un compte désactivé (rien n'est écrit, ADR 0021) et une
     * demande trop proche de la précédente ne font rien et ne lèvent rien (sinon l'endpoint public révélerait quelles
     * adresses sont inscrites). Un compte en attente reçoit le lien : l'ouvrir prouve son adresse (ADR 0026).
     */
    public function __invoke(SendPasswordResetCommand $command): void
    {
        try {
            $user = $this->userRepository->findOneByEmail($command->email);
        } catch (UserException) {
            return;
        }

        if ($user->isDisabled()) {
            return;
        }

        $lastToken = $this->tokenRepository->findOneByUserId($user->getId());
        if ($lastToken !== null && $lastToken->wasIssuedRecently($this->clock->now())) {
            return;
        }

        $this->passwordResetIssuer->issueFor($user);
    }
}

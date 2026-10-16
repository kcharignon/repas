<?php

namespace Repas\User\Application\SendEmailVerification;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Service\EmailVerificationIssuer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendEmailVerificationHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private EmailVerificationTokenRepository $tokenRepository,
        private EmailVerificationIssuer $emailVerificationIssuer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ne dit jamais si l'adresse a un compte : un compte inconnu, déjà confirmé ou désactivé, et un envoi trop rapproché du
     * précédent, ne font rien et ne lèvent rien (sinon l'endpoint public révélerait quelles adresses sont inscrites).
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

        $lastToken = $this->tokenRepository->findOneByUserId($user->getId());
        if ($lastToken !== null && $lastToken->wasIssuedRecently($this->clock->now())) {
            return;
        }

        $this->emailVerificationIssuer->reissueFor($user);
    }
}

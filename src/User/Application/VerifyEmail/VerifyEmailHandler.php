<?php

namespace Repas\User\Application\VerifyEmail;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserException lien inconnu, déjà utilisé ou expiré
     */
    public function __invoke(VerifyEmailCommand $command): void
    {
        $token = $this->tokenRepository->findOneByTokenHash($command->tokenHash);
        if ($token === null || $token->isExpired($this->clock->now())) {
            throw UserException::invalidEmailVerificationToken();
        }

        try {
            $user = $this->userRepository->findOneById($token->getUserId());
        } catch (UserException) {
            // Jeton orphelin : même réponse qu'un jeton inconnu
            throw UserException::invalidEmailVerificationToken();
        }

        $user->verifyEmail();
        $this->userRepository->save($user);
        // Le lien ne sert qu'une fois
        $this->tokenRepository->deleteAllOfUser($user->getId());
    }
}

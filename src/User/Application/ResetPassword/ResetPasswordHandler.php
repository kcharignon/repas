<?php

namespace Repas\User\Application\ResetPassword;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsMessageHandler]
readonly class ResetPasswordHandler
{
    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Le lien prouve la possession de l'adresse : il fixe le mot de passe sans connaître l'ancien (qui peut être celui d'un tiers
     * ou n'exister pas, compte créé par Google) et il active un compte en attente. Il révoque toutes les connexions du compte
     * (la version des identifiants monte, ses refresh tokens sont supprimés) et ne sert qu'une fois (ADR 0026).
     *
     * Un compte désactivé ne change rien : même refus qu'un lien inconnu, et le bus de commandes annule toute écriture (ADR 0021).
     *
     * @throws UserException lien inconnu, déjà utilisé, expiré, ou compte désactivé
     */
    public function __invoke(ResetPasswordCommand $command): void
    {
        $token = $this->tokenRepository->findOneByTokenHash($command->tokenHash);
        if ($token === null || $token->isExpired($this->clock->now())) {
            throw UserException::invalidPasswordResetToken();
        }

        try {
            $user = $this->userRepository->findOneById($token->getUserId());
        } catch (UserException) {
            // Jeton orphelin : même réponse qu'un jeton inconnu
            throw UserException::invalidPasswordResetToken();
        }

        if ($user->isDisabled()) {
            throw UserException::invalidPasswordResetToken();
        }

        $wasPending = $user->isPending();

        $user->changePassword($this->passwordHasherFactory->getPasswordHasher(User::class)->hash($command->newPassword));
        $user->verifyEmail();
        $this->userRepository->save($user);

        // Le lien ne sert qu'une fois, et les connexions ouvertes avec l'ancien mot de passe sont fermées
        $this->tokenRepository->deleteAllOfUser($user->getId());
        $this->refreshTokenRepository->deleteAllOfUser($user->getId());
        // L'adresse est confirmée par ce lien : celui de l'inscription ne sert plus
        if ($wasPending) {
            $this->emailVerificationTokenRepository->deleteAllOfUser($user->getId());
        }
    }
}

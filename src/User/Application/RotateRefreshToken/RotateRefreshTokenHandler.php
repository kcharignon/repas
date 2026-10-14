<?php

namespace Repas\User\Application\RotateRefreshToken;


use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\RefreshToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Vérifie le refresh token présenté, le consomme et enregistre son successeur (ADR 0015).
 *
 * Renvoie l'identifiant de connexion (l'email) du propriétaire, ou `null` quand le jeton est refusé. Un refus ne lève
 * pas d'exception : il doit pouvoir supprimer la famille d'un jeton rejoué, et le bus de commandes annulerait cette
 * suppression si le handler échouait (ADR 0015 §12).
 */
#[AsMessageHandler]
readonly class RotateRefreshTokenHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private UserRepository $userRepository,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RotateRefreshTokenCommand $command): ?string
    {
        $token = $this->refreshTokenRepository->findOneByTokenHash($command->presentedTokenHash);
        if ($token === null) {
            return null;
        }

        $now = $this->clock->now();

        if ($token->isUsed()) {
            // Deux onglets qui rafraîchissent ensemble envoient le même cookie : le perdant est refusé, la famille survit
            if ($token->wasJustUsed($now)) {
                return null;
            }
            $this->logger->warning('Refresh token reuse detected, the whole family is revoked.', ['user' => $token->getUserId(), 'family' => $token->getFamilyId()]);
            $this->refreshTokenRepository->deleteFamily($token->getFamilyId());

            return null;
        }

        try {
            $user = $this->userRepository->findOneById($token->getUserId());
        } catch (UserException) {
            $user = null;
        }

        if ($user === null || $token->isExpired($now) || $user->isDisabled() || $user->getCredentialsVersion() !== $token->getCredentialsVersion()) {
            $this->refreshTokenRepository->deleteFamily($token->getFamilyId());

            return null;
        }

        $next = $token->rotate($this->uuidGenerator->generate(), $command->nextTokenHash, $now);
        $this->refreshTokenRepository->save($token);
        $this->refreshTokenRepository->save($next);

        return $user->getEmail();
    }
}

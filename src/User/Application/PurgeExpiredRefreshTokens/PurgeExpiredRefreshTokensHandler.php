<?php

namespace Repas\User\Application\PurgeExpiredRefreshTokens;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class PurgeExpiredRefreshTokensHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int nombre de jetons supprimés
     */
    public function __invoke(PurgeExpiredRefreshTokensCommand $command): int
    {
        return $this->refreshTokenRepository->deleteExpired($this->clock->now());
    }
}

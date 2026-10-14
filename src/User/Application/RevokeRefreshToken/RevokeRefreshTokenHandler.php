<?php

namespace Repas\User\Application\RevokeRefreshToken;


use Repas\User\Domain\Interface\RefreshTokenRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RevokeRefreshTokenHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
    ) {
    }

    public function __invoke(RevokeRefreshTokenCommand $command): void
    {
        $token = $this->refreshTokenRepository->findOneByTokenHash($command->tokenHash);
        if ($token !== null) {
            $this->refreshTokenRepository->deleteFamily($token->getFamilyId());
        }
    }
}

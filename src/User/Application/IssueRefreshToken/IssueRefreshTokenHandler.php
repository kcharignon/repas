<?php

namespace Repas\User\Application\IssueRefreshToken;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\RefreshToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class IssueRefreshTokenHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private UserRepository $userRepository,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(IssueRefreshTokenCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $this->refreshTokenRepository->save(RefreshToken::startFamily(
            id: $this->uuidGenerator->generate(),
            familyId: $this->uuidGenerator->generate(),
            userId: $user->getId(),
            tokenHash: $command->tokenHash,
            credentialsVersion: $user->getCredentialsVersion(),
            now: $this->clock->now(),
        ));

        // Une connexion de plus que le plafond : la plus ancienne disparaît
        $this->refreshTokenRepository->deleteOldestFamiliesOfUser($user->getId(), RefreshToken::MAX_FAMILIES_PER_USER);
    }
}

<?php

namespace Repas\Voice\Application\Command\PurgeUserVoiceData;


use Repas\Voice\Domain\Interface\UserVoiceDataRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Efface les données du contexte `Voice` d'un compte supprimé (ADR 0021, ADR 0029 décision 9) : ses liaisons avec Alexa, leurs jetons et
 * ses codes d'autorisation, en masse et sans événement. Elle passe que la commande vocale soit allumée ou non : des liaisons peuvent
 * exister après une coupure.
 */
#[AsMessageHandler]
readonly class PurgeUserVoiceDataHandler
{
    public function __construct(
        private UserVoiceDataRepository $userVoiceDataRepository,
    ) {
    }

    public function __invoke(PurgeUserVoiceDataCommand $command): void
    {
        $this->userVoiceDataRepository->deleteByUser($command->userId);
    }
}

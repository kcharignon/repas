<?php

namespace Repas\User\Application\UpdateUserAppVersion;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\AppVersionException;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Domain\Model\UserAppVersion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateUserAppVersionHandler
{
    public function __construct(
        private UserAppVersionRepository $userAppVersionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Retient la version de l'application vue pour le compte (une ligne par compte et par plateforme, remplacée). Idempotent :
     * renvoyer la même version ne change que l'heure. Un compte supprimé entre-temps ne retrouve pas de ligne (le dépôt n'écrit
     * que pour un compte qui existe). Une seule requête SQL.
     *
     * @throws AppVersionException version mal formée (`422`)
     */
    public function __invoke(UpdateUserAppVersionCommand $command): void
    {
        $this->userAppVersionRepository->save(UserAppVersion::seen(
            userId: $command->userId,
            platform: $command->platform,
            version: AppVersion::fromString($command->version),
            seenAt: $this->clock->now(),
        ));
    }
}

<?php

namespace Repas\Drive\Application\Command\RememberLastRetailer;


use Psr\Clock\ClockInterface;
use Repas\Drive\Application\Service\ConfiguredRetailerGuard;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RememberLastRetailerHandler
{
    public function __construct(
        private RetailerPreferenceRepository $retailerPreferenceRepository,
        private ConfiguredRetailerGuard $configuredRetailerGuard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Retient l'enseigne que la personne vient d'ouvrir. C'est le front qui l'écrit à l'ouverture du drive (jamais une lecture : une query ne
     * modifie rien). Rien n'est écrit quand c'est déjà la dernière utilisée, ce qui est le cas de presque tous les passages.
     *
     * @throws DriveFeatureException `retailerDisabled()` (404)
     * @throws DriveException        `retailerNotConfigured()` (422)
     */
    public function __invoke(RememberLastRetailerCommand $command): void
    {
        $this->configuredRetailerGuard->assertAvailable($command->userId, $command->retailer);

        $preference = $this->retailerPreferenceRepository->findOneByUser($command->userId);
        if ($preference?->getLastUsedRetailer() === $command->retailer) {
            return;
        }

        $now = $this->clock->now();
        $preference ??= RetailerPreference::create($command->userId, $now);
        $this->retailerPreferenceRepository->save($preference->rememberLastUsed($command->retailer, $now));
    }
}

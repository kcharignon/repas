<?php

namespace Repas\Drive\Application\Command\SetDefaultRetailer;


use Psr\Clock\ClockInterface;
use Repas\Drive\Application\Service\ConfiguredRetailerGuard;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SetDefaultRetailerHandler
{
    public function __construct(
        private RetailerPreferenceRepository $retailerPreferenceRepository,
        private ConfiguredRetailerGuard $configuredRetailerGuard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Fixe le drive habituel de l'utilisateur (« ne me demande plus »), ou l'efface avec `null`. Une enseigne éteinte ou sans magasin n'est
     * jamais un drive habituel. Rien n'est écrit quand la valeur ne change pas (ni pour effacer un drive habituel qui n'existe pas).
     *
     * @throws DriveFeatureException `retailerDisabled()` (404)
     * @throws DriveException        `retailerNotConfigured()` (422)
     */
    public function __invoke(SetDefaultRetailerCommand $command): void
    {
        if ($command->retailer !== null) {
            $this->configuredRetailerGuard->assertAvailable($command->userId, $command->retailer);
        }

        $preference = $this->retailerPreferenceRepository->findOneByUser($command->userId);
        if ($preference === null && $command->retailer === null) {
            return;
        }
        if ($preference?->getDefaultRetailer() === $command->retailer) {
            return;
        }

        $now = $this->clock->now();
        $preference ??= RetailerPreference::create($command->userId, $now);
        $this->retailerPreferenceRepository->save($preference->chooseDefault($command->retailer, $now));
    }
}

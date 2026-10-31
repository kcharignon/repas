<?php

namespace Repas\Drive\Application\OpenHostService;

use Repas\Drive\Domain\Interface\DriveFeature;

/**
 * Open Host Service de lecture du contexte `Drive` (ADR 0035) : l'interrupteur global `DRIVE_ENABLED`, pour la route d'un autre contexte qui
 * n'existe que si le drive est allumé (l'envoi d'une liste). Il ne dit rien des enseignes.
 */
final readonly class DriveSwitchQueryOHS
{
    public function __construct(
        private DriveFeature $feature,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->feature->isEnabled();
    }
}

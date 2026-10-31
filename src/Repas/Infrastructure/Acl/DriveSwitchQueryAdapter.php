<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Drive\Application\OpenHostService\DriveSwitchQueryOHS;
use Repas\Repas\Application\Port\DriveSwitchQueryPort;

/**
 * Couche anticorruption (ADR 0035) : l'interrupteur du drive publié par `Drive`, tel que la route d'envoi d'une liste le demande.
 */
final readonly class DriveSwitchQueryAdapter implements DriveSwitchQueryPort
{
    public function __construct(
        private DriveSwitchQueryOHS $switch,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->switch->isEnabled();
    }
}

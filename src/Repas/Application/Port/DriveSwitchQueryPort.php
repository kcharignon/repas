<?php

namespace Repas\Repas\Application\Port;

/**
 * Le drive est-il allumé ? Adapté depuis le contexte `Drive` (ADR 0035) : l'envoi d'une liste de courses n'existe que s'il l'est.
 */
interface DriveSwitchQueryPort
{
    public function isEnabled(): bool;
}

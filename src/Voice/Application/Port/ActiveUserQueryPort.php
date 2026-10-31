<?php

namespace Repas\Voice\Application\Port;

/**
 * Ce que la lecture vocale (ADR 0029) demande aux comptes : un compte désactivé, en attente ou supprimé ne résout rien. Adapté depuis le
 * contexte `User` (ADR 0035).
 */
interface ActiveUserQueryPort
{
    public function isActive(string $userId): bool;
}

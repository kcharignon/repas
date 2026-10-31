<?php

namespace Repas\Voice\Application\Port;

/**
 * Ce que les écritures de la liaison avec Alexa (ADR 0029) demandent aux comptes : un compte désactivé, en attente ou supprimé n'échange
 * ni ne rafraîchit rien. Adapté depuis le contexte `User` (ADR 0035).
 */
interface ActiveUserCommandPort
{
    public function isActive(string $userId): bool;
}

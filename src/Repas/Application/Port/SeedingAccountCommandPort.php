<?php

namespace Repas\Repas\Application\Port;

/**
 * Ce que l'amorçage des ingrédients d'un compte (ING-008) demande au compte lui-même : s'il existe, et une empreinte de ses statistiques
 * (un amorçage n'est pas une création : elles ne bougent pas, ADR 0028 décision 7). Adapté depuis le contexte `User` (ADR 0035).
 */
interface SeedingAccountCommandPort
{
    /**
     * Le compte existe. Relu dans la transaction.
     */
    public function exists(string $userId): bool;

    /**
     * Un texte qui change dès que les statistiques du compte changent, et reste le même sinon. Vide pour un compte inconnu.
     */
    public function statisticsFingerprint(string $userId): string;
}

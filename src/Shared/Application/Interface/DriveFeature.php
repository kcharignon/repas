<?php

namespace Repas\Shared\Application\Interface;


/**
 * Interrupteur de la fonction « envoyer la liste au drive » (docs/drive/architecture.md § 5.3 et § 12).
 *
 * Coupé par défaut : tant qu'il l'est, aucune liste ne peut être envoyée et aucune route `Drive` ne répond. L'activer est
 * une décision humaine, après un déploiement stable.
 */
interface DriveFeature
{
    public function isEnabled(): bool;
}

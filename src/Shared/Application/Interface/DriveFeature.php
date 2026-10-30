<?php

namespace Repas\Shared\Application\Interface;


/**
 * Interrupteurs de la fonction « envoyer la liste au drive » (docs/drive/architecture.md § 5.3 et § 12).
 *
 * Deux niveaux :
 * - le **global** (`isEnabled()`, `DRIVE_ENABLED`) : coupé par défaut, tant qu'il l'est aucune liste ne peut être envoyée et aucune route
 *   `Drive` ne répond ;
 * - **par enseigne** (`isRetailerEnabled()`, `enabledRetailers()`, `DRIVE_RETAILERS`, DRV-702) : une enseigne éteinte n'existe pas pour
 *   l'utilisateur (absente de la sélection, ses routes répondent `404 DRIVE_RETAILER_DISABLED`) ; ses données restent en base.
 *   Le global l'emporte sur tout : il ne dit rien des enseignes.
 *
 * Allumer l'un ou l'autre est une décision humaine, après un déploiement stable.
 */
interface DriveFeature
{
    public function isEnabled(): bool;

    /**
     * L'enseigne (son identifiant, celui de la colonne `retailer`) est-elle allumée ? Un identifiant inconnu ne l'est jamais.
     */
    public function isRetailerEnabled(string $retailer): bool;

    /**
     * Les enseignes allumées (identifiants connus du registre, sans doublon, dans l'ordre de la variable).
     *
     * @return list<string>
     */
    public function enabledRetailers(): array;
}

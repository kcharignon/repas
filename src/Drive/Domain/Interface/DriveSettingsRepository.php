<?php

namespace Repas\Drive\Domain\Interface;

use DateTimeImmutable;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;

interface DriveSettingsRepository
{
    /**
     * Les réglages de l'utilisateur pour cette enseigne, ou `null` s'il n'en a pas encore.
     */
    public function findOneByUser(string $userId, Retailer $retailer): ?DriveSettings;

    /**
     * Enregistre les réglages : crée la ligne ou remplace le magasin (un seul réglage par utilisateur et par enseigne).
     */
    public function save(DriveSettings $settings): void;

    /**
     * Compte `$count` écritures de produits pour le jour de `$now` (jour UTC) dans les réglages de l'utilisateur, EN UNE instruction atomique :
     * le compteur est lu, comparé au plafond (`DriveSettings::MAX_PRODUCT_WRITES_PER_DAY`) et écrit sans que deux requêtes simultanées d'un même
     * compte puissent voir la même valeur. Tout ou rien. N'écrit rien d'autre que le compteur ; le modèle déjà chargé n'est pas mis à jour.
     *
     * @return bool vrai si les écritures sont comptées, faux si elles dépasseraient le plafond du jour (rien n'est compté) ou si les réglages n'existent pas
     */
    public function reserveProductWrites(string $userId, Retailer $retailer, int $count, DateTimeImmutable $now): bool;

    /**
     * Retire le réglage de l'utilisateur pour cette enseigne (DRV-703) : le magasin n'est plus enregistré, ses choix et les produits restent.
     * Le compteur du jour des écritures de produits part avec la ligne.
     *
     * @return bool vrai si un réglage a été retiré, faux s'il n'y en avait pas
     */
    public function delete(string $userId, Retailer $retailer): bool;

    /**
     * Supprime tous les réglages de l'utilisateur, toutes enseignes (suppression de son compte, ADR 0021).
     */
    public function deleteByUser(string $userId): void;
}

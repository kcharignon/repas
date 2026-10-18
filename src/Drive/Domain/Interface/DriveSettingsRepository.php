<?php

namespace Repas\Drive\Domain\Interface;

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
     * Supprime tous les réglages de l'utilisateur, toutes enseignes (suppression de son compte, ADR 0021).
     */
    public function deleteByUser(string $userId): void;
}

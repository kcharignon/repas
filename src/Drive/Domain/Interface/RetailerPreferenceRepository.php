<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\RetailerPreference;

interface RetailerPreferenceRepository
{
    /**
     * La préférence d'enseigne de l'utilisateur, ou `null` s'il n'en a pas encore (aucune ligne : ni drive habituel ni dernière enseigne).
     */
    public function findOneByUser(string $userId): ?RetailerPreference;

    /**
     * Enregistre la préférence : crée la ligne ou remplace ses valeurs (une seule par utilisateur).
     */
    public function save(RetailerPreference $preference): void;

    /**
     * Supprime la préférence de l'utilisateur (suppression de son compte, ADR 0021).
     */
    public function deleteByUser(string $userId): void;
}

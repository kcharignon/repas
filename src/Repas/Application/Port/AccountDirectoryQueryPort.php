<?php

namespace Repas\Repas\Application\Port;

/**
 * Ce que les rapports d'exploitation de `Repas` (santé des conversions, rapport de l'amorçage) demandent aux comptes : la liste de ceux qui
 * existent et leur statut. Ils comptent ensuite sur les tables de `Repas`. Adapté depuis le contexte `User` (ADR 0035).
 */
interface AccountDirectoryQueryPort
{
    public const string STATUS_ACTIVE = 'ACTIVE';

    /**
     * Le statut (`PENDING`, `ACTIVE` ou `DISABLED`) de chaque compte, par identifiant, dans l'ordre des identifiants.
     *
     * @return array<string, string>
     */
    public function statusesById(): array;
}

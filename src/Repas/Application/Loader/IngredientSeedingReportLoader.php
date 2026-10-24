<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;

interface IngredientSeedingReportLoader
{
    /**
     * Le rapport de l'amorçage des comptes (ING-008), en LECTURE SEULE (aucune écriture, aucun verrou) : tous les comptes avec leur statut et
     * leur périmètre, pour chaque compte retenu ce qui serait copié et repointé, ses anomalies et ses empreintes, et la requête de santé.
     *
     * @param list<string> $only identifiants complets ou références courtes ; vide : le périmètre par défaut (comptes actifs non amorcés)
     * @param list<string> $except idem, à exclure
     * @param bool $complete retient aussi un compte déjà amorcé quand `$only` le désigne
     */
    public function report(array $only, array $except, bool $complete): SeedingReportDTO;
}

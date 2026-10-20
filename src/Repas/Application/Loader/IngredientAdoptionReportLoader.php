<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionReportDTO;

interface IngredientAdoptionReportLoader
{
    /**
     * Le rapport de la migration d'adoption des communs, en LECTURE SEULE (aucune écriture, aucun verrou) : tous les comptes avec leur
     * statut et leur périmètre, et pour chaque compte concerné et retenu ce qui serait copié et repointé, ses anomalies et ses empreintes.
     *
     * @param list<string> $only identifiants complets ou références courtes ; vide : tous les comptes
     * @param list<string> $except idem, à exclure
     */
    public function report(array $only, array $except): AdoptionReportDTO;
}

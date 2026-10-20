<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;

interface CatalogHandoverReportLoader
{
    /**
     * Le rapport « à blanc » de la passation du catalogue (ING-009), en LECTURE SEULE (aucune écriture, aucun verrou) : le propriétaire, le
     * catalogue (les ingrédients sans créateur face au fichier de classement), les comptes que l'amorçage servirait (ce qu'il copierait et
     * repointerait, comme si le classement était posé), les préconditions P1 à P7 et le verdict. Aucun identifiant, aucune adresse, aucun
     * nom d'ingrédient personnel : des nombres et des références courtes.
     */
    public function report(): CatalogHandoverReportDTO;
}

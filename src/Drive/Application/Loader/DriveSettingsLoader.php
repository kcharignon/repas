<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Model\Retailer;

interface DriveSettingsLoader
{
    /**
     * Les réglages de l'utilisateur pour l'enseigne (`retailer` et `store`) et, en plus, une entrée par enseigne allumée (`retailers`), son
     * drive habituel, sa dernière enseigne et la sélection de l'enseigne à utiliser (DRV-703) ; sans réglage enregistré pour l'enseigne,
     * `store` est `null`. `enabled` est l'état de l'interrupteur du drive.
     *
     * `$retailer` à `null` : l'enseigne en vigueur, celle de la sélection (`RetailerSelection::retailerToUse()`). C'est aussi la réponse à
     * « quelle enseigne pour une demande qui n'en nomme aucune ? » (`retailer` du résultat).
     */
    public function get(string $userId, ?Retailer $retailer = null): DriveSettingsDTO;

    /**
     * Le seul magasin de l'utilisateur chez cette enseigne, `null` s'il n'en a pas enregistré. Une requête : pour les lectures qui n'ont besoin
     * que du magasin (l'estimation), sans lire la préférence ni la sélection.
     */
    public function store(string $userId, Retailer $retailer): ?DriveStoreDTO;
}

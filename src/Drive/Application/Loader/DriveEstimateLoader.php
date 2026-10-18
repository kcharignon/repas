<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Exception\DomainException;

interface DriveEstimateLoader
{
    /**
     * L'estimation du coût d'une liste (quel que soit son statut) d'après le produit préféré de chaque ligne dans le magasin
     * courant de l'utilisateur et son dernier prix vu. Les lignes suivent l'ordre de la liste (slug d'ingrédient croissant).
     *
     * @throws DriveException pas de réglages (`409 DRIVE_NOT_CONFIGURED`) ou liste d'un autre utilisateur (`403`)
     * @throws DomainException liste inconnue (`404`)
     */
    public function get(string $userId, string $shoppingListId): DriveEstimateDTO;
}

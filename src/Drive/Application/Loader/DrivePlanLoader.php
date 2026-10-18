<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Exception\DomainException;

interface DrivePlanLoader
{
    /**
     * Le plan d'envoi d'une liste « envoyée » dans le magasin courant de l'utilisateur : pour chaque ligne, ce que l'agent doit
     * faire. Les lignes suivent l'ordre de la liste (slug d'ingrédient croissant).
     *
     * @throws DriveException pas de réglages (`409 DRIVE_NOT_CONFIGURED`), liste qui n'est pas `SENT` (`409 SHOPPING_LIST_NOT_SENT`)
     *                        ou liste d'un autre utilisateur (`403`)
     * @throws DomainException liste inconnue (`404`)
     */
    public function get(string $userId, string $shoppingListId): DrivePlanDTO;
}

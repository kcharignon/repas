<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanCountsDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanLineDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanNeededDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanProductDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanRejectedDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Tests\Helper\Builder\Builder;

/**
 * Un plan d'envoi de trois lignes (connue, nouvelle, « j'en ai toujours »). Valeurs factices uniquement.
 */
class DrivePlanDTOBuilder implements Builder
{
    public function build(): DrivePlanDTO
    {
        return new DrivePlanDTO(
            shoppingListId: 'list-1',
            listVersion: 5,
            retailer: 'leclerc',
            store: new DriveStoreDTO('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456'),
            generatedAt: '2026-10-18T10:00:00+02:00',
            counts: new DrivePlanCountsDTO(1, 1, 1),
            lines: [
                new DrivePlanLineDTO(
                    'farine',
                    'Farine',
                    new DrivePlanNeededDTO(300, 'g', 'WEIGHT', 1, 'gramme', 'gramme'),
                    'KNOWN',
                    [
                        new DrivePlanProductDTO(1, '123456', null, 'Farine de blé T55 1 kg', new ProductContentDTO(1000, 'g'), 1, 'COMPUTED', null, null),
                        new DrivePlanProductDTO(2, '222333', '3017620422003', 'Farine T45', null, 1, 'COMPUTED', new PackSizeDTO(500, 'gramme'), null),
                    ],
                    [new DrivePlanRejectedDTO('999000', 'Farine complète bio 1 kg')],
                    'Farine',
                    [4001, 4002],
                ),
                new DrivePlanLineDTO('poivre', 'Poivre', new DrivePlanNeededDTO(1, 'paquet', 'PACKAGE', 1, 'paquet', 'paquet'), 'NEW', [], [], 'Poivre'),
                new DrivePlanLineDTO('sel', 'Sel', new DrivePlanNeededDTO(2, 'cuillère à soupe', null, null, 'cuillere-a-soupe', 'cuillère à soupe'), 'HAVE_AT_HOME', [], [], 'Sel'),
            ],
        );
    }
}

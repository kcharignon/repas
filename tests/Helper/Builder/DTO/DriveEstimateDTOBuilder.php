<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateLineDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Tests\Helper\Builder\Builder;

/**
 * Une estimation de cinq lignes (une par statut). Valeurs factices uniquement.
 */
class DriveEstimateDTOBuilder implements Builder
{
    public function build(): DriveEstimateDTO
    {
        return new DriveEstimateDTO(
            shoppingListId: 'list-1',
            retailer: 'leclerc',
            store: new DriveStoreDTO('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456'),
            estimatedTotalCents: 258,
            estimatedLines: 1,
            unestimatedLines: 3,
            coverage: 0.25,
            oldestPriceAt: '2026-10-12T09:30:00+02:00',
            newestPriceAt: '2026-10-12T09:30:00+02:00',
            lines: [
                new DriveEstimateLineDTO('farine', 'Farine', 'Farine de blé T55 1 kg', 2, 129, 258, '2026-10-12T09:30:00+02:00', 'ESTIMATED'),
                new DriveEstimateLineDTO('lait', 'Lait', 'Lait demi-écrémé 1 l', null, 95, null, '2026-10-12T09:30:00+02:00', 'TO_CHECK'),
                new DriveEstimateLineDTO('poivre', 'Poivre', null, null, null, null, null, 'NO_PRODUCT'),
                new DriveEstimateLineDTO('riz', 'Riz', 'Riz long 1 kg', null, null, null, null, 'NO_PRICE'),
                new DriveEstimateLineDTO('sel', 'Sel', null, null, null, null, null, 'HAVE_AT_HOME'),
            ],
            disclaimer: DriveEstimateDTO::DISCLAIMER,
        );
    }
}

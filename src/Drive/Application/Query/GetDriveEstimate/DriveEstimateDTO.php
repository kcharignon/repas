<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;

final readonly class DriveEstimateDTO
{
    public const string DISCLAIMER = "Estimation d'après les derniers prix vus ; le prix réel est celui du drive.";

    /**
     * @param list<DriveEstimateLineDTO> $lines
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste de courses', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $shoppingListId,
        #[OA\Property(description: 'Enseigne du drive', enum: ['leclerc'], example: 'leclerc')]
        public string $retailer,
        public DriveStoreDTO $store,
        #[OA\Property(description: 'Total estimé en centimes : somme des sous-totaux des lignes `ESTIMATED` seulement ; les autres lignes ne sont pas devinées', example: 1247)]
        public int $estimatedTotalCents,
        #[OA\Property(description: 'Nombre de lignes `ESTIMATED`', example: 8)]
        public int $estimatedLines,
        #[OA\Property(description: 'Nombre de lignes à acheter qui ne sont pas estimées (`NO_PRODUCT`, `NO_PRICE`, `TO_CHECK`)', example: 4)]
        public int $unestimatedLines,
        #[OA\Property(description: "Lignes estimées sur lignes à acheter (les « j'en ai toujours » ne comptent pas), de 0 à 1 arrondi à 4 décimales ; 0 quand rien n'est à acheter", minimum: 0, maximum: 1, example: 0.6667)]
        public float $coverage,
        #[OA\Property(description: 'Date du prix le plus ancien parmi les lignes du total (ISO 8601), `null` sans ligne estimée', nullable: true, example: '2026-10-12T09:30:00+02:00')]
        public ?string $oldestPriceAt,
        #[OA\Property(description: 'Date du prix le plus récent parmi les lignes du total (ISO 8601), `null` sans ligne estimée', nullable: true, example: '2026-10-18T10:00:00+02:00')]
        public ?string $newestPriceAt,
        #[OA\Property(description: "Une ligne par ingrédient de la liste, dans l'ordre de la liste (slug d'ingrédient croissant)", type: 'array', items: new OA\Items(ref: new Model(type: DriveEstimateLineDTO::class)))]
        public array $lines,
        #[OA\Property(description: "Texte fixe à afficher avec l'estimation : une estimation datée, jamais un prix garanti", example: "Estimation d'après les derniers prix vus ; le prix réel est celui du drive.")]
        public string $disclaimer,
    ) {
    }
}

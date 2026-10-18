<?php

namespace Repas\Drive\Application\Query\ListChoices;

use OpenApi\Attributes as OA;

final readonly class ChoiceProductDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant Repas du produit', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $productId,
        #[OA\Property(description: "Identifiant du produit chez l'enseigne (chiffres)", example: '123456')]
        public string $retailerProductId,
        #[OA\Property(description: 'Code-barres, si connu', example: '3017620422003', nullable: true)]
        public ?string $ean,
        #[OA\Property(description: 'Libellé du produit', example: 'Farine de blé T55 Marque Repère 1 kg')]
        public string $label,
        #[OA\Property(description: 'Contenu net, `null` si inconnu', nullable: true)]
        public ?ProductContentDTO $content,
    ) {
    }
}

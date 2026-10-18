<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;

final readonly class DrivePlanProductDTO
{
    public function __construct(
        #[OA\Property(description: 'Rang du produit : 1 = le préféré, les suivants servent de secours', example: 1)]
        public int $rank,
        #[OA\Property(description: "Identifiant du produit chez l'enseigne (chiffres)", example: '123456')]
        public string $retailerProductId,
        #[OA\Property(description: 'Code-barres, si connu', example: '3017620422003', nullable: true)]
        public ?string $ean,
        #[OA\Property(description: 'Libellé du produit', example: 'Farine de blé T55 Marque Repère 1 kg')]
        public string $label,
        #[OA\Property(description: 'Contenu net, `null` si inconnu', nullable: true)]
        public ?ProductContentDTO $content,
        #[OA\Property(description: 'Nombre de produits à mettre au panier pour la quantité demandée (1 à 99), calculé avec le contenu de ce produit ; `null` quand il ne se calcule pas', minimum: 1, maximum: 99, nullable: true, example: 1)]
        public ?int $packs,
        #[OA\Property(description: '`COMPUTED` : calcul fiable ; `TO_CHECK` : valeur par défaut ou absente, l\'agent demande la quantité', enum: ['COMPUTED', 'TO_CHECK'], example: 'COMPUTED')]
        public string $packsStatus,
    ) {
    }
}

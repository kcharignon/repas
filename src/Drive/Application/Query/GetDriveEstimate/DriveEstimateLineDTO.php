<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use OpenApi\Attributes as OA;

final readonly class DriveEstimateLineDTO
{
    public function __construct(
        #[OA\Property(description: "Slug de l'ingrédient", example: 'farine')]
        public string $ingredientSlug,
        #[OA\Property(description: "Nom de l'ingrédient", example: 'Farine')]
        public string $ingredientName,
        #[OA\Property(description: "Libellé du produit préféré (rang 1) ; `null` si l'ingrédient n'a pas de produit accepté dans ce magasin ou est « j'en ai toujours »", nullable: true, example: 'Farine de blé T55 Marque Repère 1 kg')]
        public ?string $productLabel,
        #[OA\Property(description: 'Nombre de produits à acheter (1 à 99) quand il se calcule de façon sûre, sinon `null`', nullable: true, minimum: 1, maximum: 99, example: 1)]
        public ?int $packs,
        #[OA\Property(description: 'Dernier prix vu du produit en centimes (TTC) ; `null` sans produit ou sans prix mémorisé', nullable: true, example: 129)]
        public ?int $unitPriceCents,
        #[OA\Property(description: 'Nombre de produits multiplié par le prix, en centimes ; renseigné pour `ESTIMATED` seulement', nullable: true, example: 129)]
        public ?int $subtotalCents,
        #[OA\Property(description: 'Date à laquelle le prix a été vu (ISO 8601) ; `null` sans prix', nullable: true, example: '2026-10-12T09:30:00+02:00')]
        public ?string $priceSeenAt,
        #[OA\Property(
            description: "`ESTIMATED` : dans le total ; `NO_PRODUCT` : aucun produit accepté ; `NO_PRICE` : le produit préféré n'a pas de prix mémorisé ; `TO_CHECK` : le nombre de produits ne se calcule pas de façon sûre ; `HAVE_AT_HOME` : « j'en ai toujours », hors total et hors couverture",
            enum: ['ESTIMATED', 'NO_PRODUCT', 'NO_PRICE', 'TO_CHECK', 'HAVE_AT_HOME'],
            example: 'ESTIMATED',
        )]
        public string $status,
    ) {
    }
}

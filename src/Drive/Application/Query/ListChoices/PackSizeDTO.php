<?php

namespace Repas\Drive\Application\Query\ListChoices;

use OpenApi\Attributes as OA;

final readonly class PackSizeDTO
{
    public function __construct(
        #[OA\Property(description: "Combien de l'unité ci-dessous tient dans le produit : de 0,001 à 9 999, trois décimales au plus", example: 20)]
        public float $value,
        #[OA\Property(description: "Slug de l'unité de la liste dans laquelle la personne a compté le produit (`unitSlug` d'une ligne du plan)", example: 'tranche')]
        public string $unitSlug,
    ) {
    }
}

<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateConversion;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'une conversion, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class ConversionPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité de départ", example: 'plaquette')]
        public string $startUnitSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité d'arrivée", example: 'gramme')]
        public string $endUnitSlug,
        #[Assert\Positive]
        #[OA\Property(description: "Une unité de départ vaut ce nombre d'unités d'arrivée", example: 250)]
        public float $coefficient,
        #[OA\Property(description: "Slug de l'ingrédient concerné ; absent ou null : conversion commune à tous les ingrédients", example: 'beurre', nullable: true)]
        public ?string $ingredientSlug = null,
    ) {
    }
}

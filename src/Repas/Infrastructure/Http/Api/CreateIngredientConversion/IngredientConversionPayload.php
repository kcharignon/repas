<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredientConversion;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'une conversion propre à un ingrédient, pour l'ajout (`POST`) comme pour le remplacement complet (`PUT`). Jamais d'ingrédient dans le
 * corps : c'est celui du chemin.
 */
final readonly class IngredientConversionPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité de départ", example: 'cuillere-a-soupe')]
        public string $startUnitSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité d'arrivée (différente de celle de départ)", example: 'gramme')]
        public string $endUnitSlug,
        #[Assert\Positive]
        #[OA\Property(description: "1 unité de départ = ce nombre d'unités d'arrivée", example: 10)]
        public float $coefficient,
    ) {
    }
}

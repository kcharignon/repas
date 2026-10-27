<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredientConversion;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
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
        #[Assert\GreaterThanOrEqual(QuantityBounds::MIN_COEFFICIENT, message: PayloadMessages::COEFFICIENT)]
        #[Assert\LessThanOrEqual(QuantityBounds::MAX_COEFFICIENT, message: PayloadMessages::COEFFICIENT)]
        #[OA\Property(description: "1 unité de départ = ce nombre d'unités d'arrivée, de 0,000001 à 1 000 000", example: 10, minimum: 0.000001, maximum: 1000000)]
        public float $coefficient,
    ) {
    }
}

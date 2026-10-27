<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecipeRowPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'ingrédient (un ingrédient de l'auteur de la recette)", example: 'pate')]
        public string $ingredientSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité de la quantité", example: 'gramme')]
        public string $unitSlug,
        #[Assert\Positive(message: PayloadMessages::QUANTITY)]
        #[Assert\LessThanOrEqual(QuantityBounds::MAX_QUANTITY, message: PayloadMessages::QUANTITY)]
        #[OA\Property(description: 'Quantité, dans cette unité, pour le nombre de personnes de la recette : strictement positive, un million au plus', example: 500, exclusiveMinimum: 0, maximum: 1000000)]
        public float $quantity,
    ) {
    }
}

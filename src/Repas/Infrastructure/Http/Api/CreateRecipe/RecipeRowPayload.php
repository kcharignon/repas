<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecipeRowPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'ingrédient (commun, ou personnalisé par l'auteur de la recette)", example: 'pate')]
        public string $ingredientSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité de la quantité", example: 'gramme')]
        public string $unitSlug,
        #[Assert\Positive]
        #[OA\Property(description: 'Quantité, dans cette unité, pour le nombre de personnes de la recette', example: 500)]
        public float $quantity,
    ) {
    }
}
